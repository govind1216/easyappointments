<?php extend('layouts/message_layout') ?>

<?php section('content') ?>

<div>
    <img id="message-icon" src="<?= $message_icon ?>" alt="warning">
</div>

<div>
    <h3><?= $message_title ?></h3>

    <p><?= $message_text ?></p>
</div>

<?php section('content') ?>

