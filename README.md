# PHP Coding Standards Fixer opinado

Set de reglas opinadas para [php-cs-fixer](https://github.com/FriendsOfPHP/PHP-CS-Fixer).

## Instalación

```shell
composer require --dev josecl/php-cs-fixer-custom-fixers
```

# Uso

En tu archivo de configuración de PHP Coding Standards Fixer,
por ejemplo `.php-cs-fixer.php`,
crea una instancia de `Finder` y luego pásala como
argumento a `CustomFixer::config()`.

```php
<?php

$finder = Symfony\Component\Finder\Finder::create()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/config',
        // etc...
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return \Josecl\PhpCsFixerCustomFixers\CustomFixers::config($finder);
```
