<?php
/* Le manifeste de l'application installable.
 *
 * Il passe par PHP parce que le serveur de Jeedom ne sert pas les
 * fichiers .json ni .webmanifest : sa liste d'extensions autorisées ne
 * les contient pas. Aucune donnée personnelle ici, donc aucune clé
 * n'est exigée.
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode(array(
    'name' => 'Le car de la maison au lycée',
    'short_name' => 'Son car',
    'description' => "Le car scolaire, ses horaires officiels et le repli s'il est manqué.",
    'start_url' => './',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#e7ecf1',
    'theme_color' => '#be7b1c',
    'lang' => 'fr',
    'categories' => array('travel', 'education'),
    'icons' => array(
        array('src' => 'icones/icone-192.png', 'sizes' => '192x192', 'type' => 'image/png'),
        array('src' => 'icones/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png'),
        array('src' => 'icones/icone-192-maskable.png', 'sizes' => '192x192',
              'type' => 'image/png', 'purpose' => 'maskable'),
        array('src' => 'icones/icone-512-maskable.png', 'sizes' => '512x512',
              'type' => 'image/png', 'purpose' => 'maskable'),
    ),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
