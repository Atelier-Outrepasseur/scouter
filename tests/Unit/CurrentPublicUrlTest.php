<?php

describe('current_public_url (retour après connexion au hub)', function () {

    beforeEach(function () {
        $this->saved = $_SERVER;
    });
    afterEach(function () {
        $_SERVER = $this->saved;
    });

    it('utilise https derrière Traefik grâce à X-Forwarded-Proto', function () {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'seo.outrepasseur.fr';
        $_SERVER['REQUEST_URI'] = '/dashboard.php?crawl=43&page=export';
        expect(current_public_url())->toBe('https://seo.outrepasseur.fr/dashboard.php?crawl=43&page=export');
    });

    it('ignore une valeur X-Forwarded-Proto farfelue', function () {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'javascript';
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'seo.outrepasseur.fr';
        expect(current_public_url('/'))->toBe('http://seo.outrepasseur.fr/');
    });

    it('reprend l\'hôte d\'origine transmis par le proxy, s\'il est valide', function () {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'scouter:8080';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'seo.outrepasseur.fr';
        expect(current_public_url('/'))->toBe('https://seo.outrepasseur.fr/');
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example/x?';
        expect(current_public_url('/'))->toBe('https://scouter:8080/');
    });

    it('sans en-tête, se fie à HTTPS', function () {
        unset($_SERVER['HTTP_X_FORWARDED_HOST']);
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'seo.outrepasseur.fr';
        expect(current_public_url('/index.php'))->toBe('https://seo.outrepasseur.fr/index.php');
    });
});
