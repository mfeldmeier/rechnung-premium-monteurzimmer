<?php
/**
 * @var string                                        $title
 * @var string                                        $content
 * @var string                                        $active
 * @var array<string,mixed>|null                      $user
 * @var list<array{type:string,message:string}>       $flashes
 */
$nav = [
    'dashboard'  => ['/', 'Übersicht'],
    'invoices'   => ['/rechnungen', 'Rechnungen'],
    'customers'  => ['/kunden', 'Kunden'],
    'apartments' => ['/wohnungen', 'Wohnungen'],
    'presets'    => ['/bausteine', 'Bausteine'],
    'settings'   => ['/einstellungen', 'Einstellungen'],
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?= e($title) ?> – Premium Monteurzimmer</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/logo.png">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">
        <img src="/assets/logo.svg" alt="" height="28">
        <span>Rechnungen</span>
    </a>
    <?php if ($user !== null): ?>
        <nav class="mainnav">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= e($href) ?>"<?= $active === $key ? ' class="is-active"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="logout" method="post" action="/logout">
            <?= csrfField() ?>
            <span class="who"><?= e($user['name'] !== '' ? $user['name'] : $user['email']) ?></span>
            <button type="submit" class="btn btn-plain">Abmelden</button>
        </form>
    <?php endif; ?>
</header>

<main class="wrap">
    <?php foreach ($flashes as $flash): ?>
        <p class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<script src="/assets/app.js" defer></script>
</body>
</html>
