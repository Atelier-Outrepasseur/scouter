<?php

namespace App\Auth;

use App\Database\UserRepository;
use App\Database\ProjectRepository;
use App\Database\CrawlRepository;
use App\Database\CrawlDatabase;

/**
 * Gestion de l'authentification et des autorisations
 * 
 * Cette classe gère l'ensemble du système d'authentification utilisateur :
 * - Délégation de l'auth au hub central seo-hub
 * - Synchronisation des users avec la BDD Scouter
 * - Gestion des rôles (admin, user, viewer)
 * - Contrôle d'accès aux projets et crawls
 * - Partage de projets entre utilisateurs
 * 
 * @package    Scouter
 * @subpackage Auth
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      1.0.0
 */
class Auth
{
    private ?UserRepository $users;
    private ?ProjectRepository $projects;
    private ?CrawlRepository $crawls;
    private static $sessionStarted = false;
    private static $hubChecked = false;

    /**
     * @param UserRepository|null $users Injection pour tests
     * @param ProjectRepository|null $projects Injection pour tests
     * @param CrawlRepository|null $crawls Injection pour tests
     * @param bool $skipDb Si true, n'instancie pas les repos (pour tests unitaires)
     */
    public function __construct(
        ?UserRepository $users = null,
        ?ProjectRepository $projects = null,
        ?CrawlRepository $crawls = null,
        bool $skipDb = false
    ) {
        if ($skipDb) {
            $this->users = null;
            $this->projects = null;
            $this->crawls = null;
        } else {
            $this->users = $users ?? new UserRepository();
            $this->projects = $projects ?? new ProjectRepository();
            $this->crawls = $crawls ?? new CrawlRepository();
        }
        $this->startSession();
        
        // Vérifier le cookie hub et synchroniser l'user (une seule fois par requête)
        if (!$skipDb && !self::$hubChecked) {
            self::$hubChecked = true;
            $this->initFromHub();
        }
    }

    /**
     * Démarre la session si elle n'est pas déjà démarrée
     */
    private function startSession()
    {
        if (!self::$sessionStarted && session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
            self::$sessionStarted = true;
        }
    }

    /**
     * Vérifie le cookie hub_session, synchronise l'user dans la BDD Scouter,
     * et peuple $_SESSION pour que le reste de la classe fonctionne normalement.
     */
    private function initFromHub(): void
    {
        $hubUser = \verify_hub_session();
        
        if (!$hubUser) {
            // Pas de session hub valide : nettoyer la session locale
            $_SESSION['logged_in'] = false;
            $_SESSION['user_id'] = null;
            $_SESSION['email'] = null;
            $_SESSION['role'] = null;
            return;
        }
        
        // Synchroniser/créer l'user dans la BDD Scouter
        try {
            $existing = $this->users->getByEmail($hubUser['email']);
            
            if ($existing) {
                $userId = (int)$existing->id;
                // Sync du rôle si différent
                if (($existing->role ?? 'user') !== $hubUser['role']) {
                    $this->users->update($userId, ['role' => $hubUser['role']]);
                }
            } else {
                // Création d'un nouvel user (password aléatoire, jamais utilisé)
                $randomPassword = bin2hex(random_bytes(32));
                $userId = $this->users->create($hubUser['email'], $randomPassword, $hubUser['role']);
            }
            
            // Peupler la session pour que le reste de la classe fonctionne
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $userId;
            $_SESSION['email'] = $hubUser['email'];
            $_SESSION['role'] = $hubUser['role'];
        } catch (\Exception $e) {
            error_log('[Auth] Hub sync error: ' . $e->getMessage());
            $_SESSION['logged_in'] = false;
            $_SESSION['user_id'] = null;
        }
    }

    /**
     * @deprecated Auth déléguée au hub - cette méthode ne fait plus rien.
     * Conservée pour compatibilité avec login.php.
     * @return bool Toujours false
     */
    public function login($email, $password)
    {
        return false;
    }

    /**
     * Déconnecte l'utilisateur localement.
     * Pour une déconnexion globale, redirige vers hub_url() . '/logout'
     */
    public function logout()
    {
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        self::$sessionStarted = false;
        self::$hubChecked = false;
    }

    /**
     * Vérifie si un utilisateur est connecté
     * @return bool True si connecté
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * Récupère l'utilisateur connecté
     * @return object|null L'utilisateur connecté ou null
     */
    public function getCurrentUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return $this->users->getById($_SESSION['user_id']);
    }

    /**
     * Récupère l'ID de l'utilisateur connecté
     * @return int|null L'ID de l'utilisateur ou null
     */
    public function getCurrentUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Récupère l'email de l'utilisateur connecté
     * @return string|null L'email ou null
     */
    public function getCurrentEmail()
    {
        return $_SESSION['email'] ?? null;
    }

    /**
     * Redirige vers la page de login (= le hub) si l'utilisateur n'est pas connecté
     * @param string $loginUrl Ignoré, on redirige toujours vers le hub
     */
    public function requireLogin($loginUrl = null)
    {
        if (!$this->isLoggedIn()) {
            $currentUrl = \current_public_url();
            header('Location: ' . \hub_url() . '/login?redirect=' . urlencode($currentUrl));
            exit;
        }
    }

    /**
     * @deprecated L'auth est déléguée au hub, donc on considère toujours que des users existent.
     * Conservée pour compatibilité avec login.php.
     */
    public function hasUsers()
    {
        return true;
    }

    /**
     * @deprecated Les users sont créés via le hub.
     * Conservée pour compatibilité.
     */
    public function register($email, $password, $role = 'user')
    {
        if ($this->users->emailExists($email)) {
            return false;
        }
        return $this->users->create($email, $password, $role);
    }

    // ==================== ROLE & PERMISSION METHODS ====================

    /**
     * Récupère le rôle de l'utilisateur connecté
     * @return string|null Le rôle ou null si non connecté
     */
    public function getCurrentRole()
    {
        return $_SESSION['role'] ?? null;
    }

    /**
     * Vérifie si l'utilisateur connecté a un rôle spécifique
     * @param string $role Le rôle à vérifier (admin, user, viewer)
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }
        return $this->getCurrentRole() === $role;
    }

    /**
     * Vérifie si l'utilisateur connecté est admin
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Vérifie si l'utilisateur connecté est viewer (lecture seule)
     * @return bool
     */
    public function isViewer(): bool
    {
        return $this->hasRole('viewer');
    }

    /**
     * Vérifie si l'utilisateur peut créer des crawls/projets
     * @return bool True si admin ou user (pas viewer)
     */
    public function canCreate(): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }
        $role = $this->getCurrentRole();
        return $role === 'admin' || $role === 'user';
    }

    /**
     * Requiert que l'utilisateur puisse créer des projets/crawls
     * @param bool $isApi Si true, retourne JSON. Si false, affiche page HTML
     */
    public function requireCanCreate(bool $isApi = true): void
    {
        if (!$this->canCreate()) {
            if ($isApi) {
                http_response_code(403);
                die(json_encode(['error' => 'Vous n\'avez pas les droits de création']));
            } else {
                http_response_code(403);
                die('<!DOCTYPE html><html><head><title>403 Accès refusé</title></head><body><h1>403 - Accès refusé</h1><p>Vous n\'avez pas les droits de création.</p></body></html>');
            }
        }
    }

    /**
     * Vérifie si l'utilisateur peut accéder à un projet
     * Retourne true si : Admin OU Propriétaire OU Partagé via project_shares
     * @param int $projectId ID du projet
     * @return bool
     */
    public function canAccessProject(int $projectId): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin a accès à tout
        if ($this->isAdmin()) {
            return true;
        }

        $userId = $this->getCurrentUserId();
        
        // Vérifie si propriétaire ou partagé
        return $this->projects->userCanAccess($userId, $projectId);
    }

    /**
     * Vérifie si l'utilisateur peut gérer un projet (modifier, supprimer, partager)
     * Retourne true si : Admin OU Propriétaire
     * Refusé pour les utilisateurs en partage (Lecture seule)
     * @param int $projectId ID du projet
     * @return bool
     */
    public function canManageProject(int $projectId): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin peut tout gérer
        if ($this->isAdmin()) {
            return true;
        }

        $userId = $this->getCurrentUserId();
        
        // Seul le propriétaire peut gérer (pas les partages)
        return $this->projects->isOwner($userId, $projectId);
    }

    /**
     * Vérifie si l'utilisateur peut accéder à un crawl
     * Basé sur l'accès au projet parent
     * @param int $crawlId ID du crawl
     * @return bool
     */
    public function canAccessCrawl(int $crawlId): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin a accès à tout
        if ($this->isAdmin()) {
            return true;
        }

        // Récupérer le projet du crawl
        $crawl = $this->crawls->getById($crawlId);
        if (!$crawl || !$crawl->project_id) {
            return false;
        }

        return $this->canAccessProject($crawl->project_id);
    }

    /**
     * Vérifie si l'utilisateur peut gérer un crawl
     * Basé sur la gestion du projet parent
     * @param int $crawlId ID du crawl
     * @return bool
     */
    public function canManageCrawl(int $crawlId): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin peut tout gérer
        if ($this->isAdmin()) {
            return true;
        }

        // Récupérer le projet du crawl
        $crawl = $this->crawls->getById($crawlId);
        if (!$crawl || !$crawl->project_id) {
            return false;
        }

        return $this->canManageProject($crawl->project_id);
    }

    /**
     * Requiert que l'utilisateur puisse accéder au projet, sinon erreur 403
     * @param int $projectId ID du projet
     */
    public function requireProjectAccess(int $projectId): void
    {
        if (!$this->canAccessProject($projectId)) {
            http_response_code(403);
            die(json_encode(['error' => 'Accès refusé à ce projet']));
        }
    }

    /**
     * Requiert que l'utilisateur puisse gérer le projet, sinon erreur 403
     * @param int $projectId ID du projet
     */
    public function requireProjectManagement(int $projectId): void
    {
        if (!$this->canManageProject($projectId)) {
            http_response_code(403);
            die(json_encode(['error' => 'Droits insuffisants (Lecture seule)']));
        }
    }

    // ==================== CRAWL PATH-BASED METHODS ====================

    /**
     * Vérifie si l'utilisateur peut accéder à un crawl par son chemin
     * @param string $crawlPath Chemin du crawl (ex: "lokoe-fr-20241201-120000")
     * @return bool
     */
    public function canAccessCrawlByPath(string $crawlPath): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin a accès à tout
        if ($this->isAdmin()) {
            return true;
        }

        // Récupérer le crawl par son chemin
        $crawl = $this->crawls->getByPath($crawlPath);
        if (!$crawl || !$crawl->project_id) {
            return false;
        }

        return $this->canAccessProject($crawl->project_id);
    }

    /**
     * Vérifie si l'utilisateur peut gérer un crawl par son chemin
     * @param string $crawlPath Chemin du crawl
     * @return bool
     */
    public function canManageCrawlByPath(string $crawlPath): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin peut tout gérer
        if ($this->isAdmin()) {
            return true;
        }

        // Récupérer le crawl par son chemin
        $crawl = $this->crawls->getByPath($crawlPath);
        if (!$crawl || !$crawl->project_id) {
            return false;
        }

        return $this->canManageProject($crawl->project_id);
    }

    /**
     * Vérifie si l'utilisateur peut gérer un crawl par son ID
     * @param int $crawlId ID du crawl
     * @return bool
     */
    public function canManageCrawlById(int $crawlId): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin peut tout gérer
        if ($this->isAdmin()) {
            return true;
        }

        // Récupérer le crawl par son ID
        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl || !$crawl->project_id) {
            return false;
        }

        return $this->canManageProject($crawl->project_id);
    }

    /**
     * Vérifie si l'utilisateur peut accéder à un crawl par son ID
     * @param int $crawlId ID du crawl
     * @return bool
     */
    public function canAccessCrawlById(int $crawlId): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        // Admin voit tout
        if ($this->isAdmin()) {
            return true;
        }

        // Récupérer le crawl
        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl || !$crawl->project_id) {
            return false;
        }

        return $this->canAccessProject($crawl->project_id);
    }

    /**
     * Requiert l'accès au crawl par ID, sinon erreur 403
     * @param int $crawlId ID du crawl
     * @param bool $isApi Si true, retourne JSON. Si false, affiche page HTML
     */
    public function requireCrawlAccessById(int $crawlId, bool $isApi = true): void
    {
        if (!$this->isLoggedIn()) {
            if ($isApi) {
                http_response_code(401);
                die(json_encode(['error' => 'Non authentifié']));
            } else {
                $this->redirectToHubLogin();
            }
        }

        if (!$this->canAccessCrawlById($crawlId)) {
            if ($isApi) {
                http_response_code(403);
                die(json_encode(['error' => 'Accès refusé à ce projet']));
            } else {
                http_response_code(403);
                die('<!DOCTYPE html><html><head><title>403 Accès refusé</title></head><body><h1>403 - Accès refusé</h1><p>Vous n\'avez pas accès à ce projet.</p></body></html>');
            }
        }
    }

    /**
     * Requiert l'accès au crawl par chemin, sinon erreur 403
     * @param string $crawlPath Chemin du crawl
     * @param bool $isApi Si true, retourne JSON. Si false, affiche page HTML
     */
    public function requireCrawlAccess(string $crawlPath, bool $isApi = true): void
    {
        if (!$this->isLoggedIn()) {
            if ($isApi) {
                http_response_code(401);
                die(json_encode(['error' => 'Non authentifié']));
            } else {
                $this->redirectToHubLogin();
            }
        }

        if (!$this->canAccessCrawlByPath($crawlPath)) {
            if ($isApi) {
                http_response_code(403);
                die(json_encode(['error' => 'Accès refusé à ce projet']));
            } else {
                http_response_code(403);
                die('<!DOCTYPE html><html><head><title>403 Accès refusé</title></head><body><h1>403 - Accès refusé</h1><p>Vous n\'avez pas accès à ce projet.</p></body></html>');
            }
        }
    }

    /**
     * Requiert la gestion du crawl par chemin, sinon erreur 403
     * @param string $crawlPath Chemin du crawl
     * @param bool $isApi Si true, retourne JSON. Si false, affiche page HTML
     */
    public function requireCrawlManagement(string $crawlPath, bool $isApi = true): void
    {
        // D'abord vérifier l'accès
        $this->requireCrawlAccess($crawlPath, $isApi);

        if (!$this->canManageCrawlByPath($crawlPath)) {
            if ($isApi) {
                http_response_code(403);
                die(json_encode(['error' => 'Droits insuffisants (Lecture seule)']));
            } else {
                http_response_code(403);
                die('<!DOCTYPE html><html><head><title>403 Droits insuffisants</title></head><body><h1>403 - Droits insuffisants</h1><p>Vous n\'avez pas les droits de modification sur ce projet.</p></body></html>');
            }
        }
    }

    /**
     * Requiert d'être admin, sinon erreur 403
     * @param bool $isApi Si true, retourne JSON. Si false, affiche page HTML
     */
    public function requireAdmin(bool $isApi = true): void
    {
        if (!$this->isLoggedIn()) {
            if ($isApi) {
                http_response_code(401);
                die(json_encode(['error' => 'Non authentifié']));
            } else {
                $this->redirectToHubLogin();
            }
        }

        if (!$this->isAdmin()) {
            if ($isApi) {
                http_response_code(403);
                die(json_encode(['error' => 'Accès réservé aux administrateurs']));
            } else {
                http_response_code(403);
                die('<!DOCTYPE html><html><head><title>403 Accès refusé</title></head><body><h1>403 - Accès refusé</h1><p>Cette page est réservée aux administrateurs.</p></body></html>');
            }
        }
    }

    /**
     * Requiert la connexion, sinon redirige vers le hub
     */
    public function requireLoginOrRedirect(): void
    {
        if (!$this->isLoggedIn()) {
            $this->redirectToHubLogin();
        }
    }

    /**
     * Requiert la connexion pour une API, sinon erreur 401
     */
    public function requireLoginApi(): void
    {
        if (!$this->isLoggedIn()) {
            http_response_code(401);
            die(json_encode(['error' => 'Non authentifié']));
        }
    }

    /**
     * Helper privé : redirige vers la page de login du hub avec le redirect en paramètre
     */
    private function redirectToHubLogin(): void
    {
        $currentUrl = \current_public_url();
        header('Location: ' . \hub_url() . '/login?redirect=' . urlencode($currentUrl));
        exit;
    }
}