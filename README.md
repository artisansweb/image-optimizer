# Image optimization using PHP

This library helps you to compress JPGs, PNGs, GIFs images on the fly. Apart from this package, you don't need to install any additional software or package to perform optimization task.

## Installation

You can install the package via composer:

```bash
composer require artisansweb/image-optimizer
```

Under the hood, this package uses [resmush.it](http://resmush.it) service to compress the images. Alternatively, package using native PHP functions - [imagecreatefromjpeg](https://www.php.net/manual/en/function.imagecreatefromjpeg.php), [imagecreatefrompng](https://www.php.net/manual/en/function.imagecreatefrompng.php), [imagecreatefromgif](https://www.php.net/manual/en/function.imagecreatefromgif.php), [imagejpeg](https://www.php.net/manual/en/function.imagejpeg.php).

## Usage

This package is straight-forward to use. All you need to do is pass source path of your image.

```php
use ArtisansWeb\Optimizer;

$img = new Optimizer();

$source = 'SOURCE_PATH_OF_IMAGE';
$img->optimize($source);
```

Above code will optimize the image and replace the original image with the optimized version.

Optionally, you can also pass destination path where optimized version will stored.

```php
$source = 'SOURCE_PATH_OF_IMAGE';
$destination = 'DESTINATION_PATH_OF_IMAGE';
$img->optimize($source, $destination);
```

Recommeded way of using this code is on image upload. The user should optimize image on upload which will result in better performance.

Let's say you want to store optimized version in the 'images' folder. You can use the below code for this purpose.

```php
<?php
require_once "vendor/autoload.php";

use ArtisansWeb\Optimizer;

if (isset($_POST['submit'])) {
    $img = new Optimizer();
    move_uploaded_file($_FILES['file']['tmp_name'], 'images/'.$_FILES['file']['name']);
    $img->optimize('images/'.$_FILES['file']['name']);
}
?>

<form method="post" enctype="multipart/form-data">
    <input type="file" name="file" />
    <input type="submit" name="submit" value="Submit" />
</form>
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
