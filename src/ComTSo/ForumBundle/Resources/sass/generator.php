 <?php

 $themes = [
    'cerulean' => 'Cerulean',
    'cosmo' => 'Cosmo',
    'cyborg' => 'Cyborg',
    'darkly' => 'Darkly',
    'flatly' => 'Flatly',
    'journal' => 'Journal',
    'lumen' => 'Lumen',
    'paper' => 'Paper',
    'readable' => 'Readable',
    'sandstone' => 'Sandstone',
    'simplex' => 'Simplex',
    'slate' => 'Slate',
    'spacelab' => 'Spacelab',
    'superhero' => 'Superhero',
    'united' => 'United',
    'yeti' => 'Yeti',
];

foreach ($themes as $theme => $label) {
    $filepath = __DIR__ . "/comtso-{$theme}.scss";
    if (file_exists($filepath)) {
        continue;
    }
    $contents = <<<EOF
// {$label} bootstrap theme

// Theme variables
@import "{$theme}/variables";

@import "bootstrap";

// Theme
@import "{$theme}/bootswatch";

@import "common";
EOF;
    file_put_contents($filepath, $contents);
}
