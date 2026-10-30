<?php
/*
 * header.php - the top bar shared by art-main.php and artwork.php:
 * "Art Gallery" title, welcome message, Add Artwork button and Logout.
 * Expects $currentUser (from auth.php) and $activePage ('main' or 'add').
 */
?>
<header class="ui inverted menu gallery-bar">
   <div class="ui container">
      <a class="header item gallery-brand" href="art-main.php">
         <i class="paint brush icon"></i> Art Gallery
      </a>
      <a class="item<?= $activePage === 'main' ? ' active' : '' ?>" href="art-main.php">
         <i class="images outline icon"></i> Artworks
      </a>
      <div class="right menu">
         <div class="item welcome" id="welcome">
            <i class="user circle icon"></i>
            Welcome,&nbsp;<strong><?= e($currentUser['firstname'] . ' ' . $currentUser['lastname']) ?></strong>
         </div>
         <div class="item">
            <a class="ui green button<?= $activePage === 'add' ? ' disabled' : '' ?>" href="artwork.php" id="add-button">
               <i class="plus icon"></i> Add Artwork
            </a>
         </div>
         <a class="item" href="logout.php" id="logout-link">
            <i class="sign out icon"></i> Logout
         </a>
      </div>
   </div>
</header>
<?php foreach (takeFlashes() as $flash): ?>
<div class="ui container flash-area">
   <div class="ui <?= $flash['type'] === 'success' ? 'positive' : 'negative' ?> message flash">
      <i class="<?= $flash['type'] === 'success' ? 'check circle' : 'exclamation triangle' ?> icon"></i>
      <?= e($flash['message']) ?>
   </div>
</div>
<?php endforeach; ?>
