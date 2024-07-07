<?php
  const APP_NAME = 'Portflow';

  if (!file_exists(__DIR__ . '/includes/core/config.php')) {
    header('Location: setup.php');
    exit;
  }

  // import auth
  include_once __DIR__ . '/includes/core/auth.php';
  use Portflow\Core\Auth;

  $auth = new Auth();

  if (isset($_GET['signup'])) {
    $action = '?signup';
    $instead = '<a class="text-gray-500 underline" href="./">login instead</a>';
    $button = 'Sign Up';
  } else {
    $action = '?signin';
    $instead = '<a class="text-gray-500 underline" href="?signup">register instead</a>';
    $button = 'Sign In';
  }

  if (isset($_GET['code']) && isset($_GET['email'])){
    $auth->verify($_GET['code'], $_GET['email']);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_GET['signup'])) {
      $auth->signup();
    } elseif (isset($_GET['signin'])) {
      $auth->signin();
    }
  }

  // Import alert function
  include_once __DIR__ . '/includes/alert.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portflow</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="flex flex-col items-center justify-center h-screen bg-gray-200">
  <div class="bg-white shadow-lg rounded-2xl p-12 w-full max-w-lg">
    <form class="m-0" method="POST" action="<?php echo $action ?>" enctype="multipart/form-data">
      <div class='flex justify-center items-center pb-12'>
        <img src='includes/img/portflow.png' alt='Portflow' class='max-h-18'>
      </div>
      <?php
        if (isset($_GET['signup'])) {
          echo '
            <div class="pb-6">
              <label class="block mb-2" for="email">
                E-Mail
              </label>
              <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="email" type="email" placeholder="E-Mail" name="email">
            </div>';
        }
      ?>
      <div class="pb-6">
        <label class="block mb-2" for="username">
          Username
        </label>
        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="username" type="text" placeholder="username" name="username">
      </div>
      <div class="pb-6">
        <label class="block mb-2" for="password">
          Password
        </label>
        <input class="appearance-none border rounded-full w-full py-2 px-3 leading-tight focus:outline-none focus:shadow-outline" id="password" type="password" placeholder="password" name="password">
      </div>
      <div class="pt-6 flex justify-between items-center">
        <input type="hidden" name="csrf" value="<?php echo $auth->csrf(); ?>">
        <?php echo $instead; ?>
        <input class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full focus:outline-none focus:shadow-outline" type="submit" value="<?php echo $button; ?>">
      </div>
    </form>
  </div>
</body>
</html>
