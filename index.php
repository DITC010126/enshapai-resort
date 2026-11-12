<?php require __DIR__ . '/app.php'; render_header('Welcome'); ?>
<section class="hero">
  <h1>Welcome to Enshapai Resort</h1>
  <p>Your base for the World Rally Championship. Comfortable rooms, great food and easy access to the stages.</p>
  <a class="btn" href="rooms.php">Check availability</a>
</section>
<section class="cards">
  <div class="card"><h3>Rally Weekend Ready</h3><p>Early breakfast, packed lunches and shuttle information for spectators.</p></div>
  <div class="card"><h3>Book in minutes</h3><p>Create an account, pick your dates and pay securely by bank card.</p></div>
  <div class="card"><h3>Secure by design</h3><p>Hashed passwords, protected forms and no card numbers stored.</p></div>
</section>
<?php render_footer();
