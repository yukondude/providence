<?php
    # Use exiftool to "de-rotate" all images in the dir parameter of the querystring under the providence/import/
    # directory.

    # Do not time out, as this script may take minutes to complete.
    set_time_limit(0);

    if (!isset($_GET['dir'])) {
        print('The name of the directory to de-rotate was not supplied.');
        exit;
    }

    $derotate_path = dirname(dirname(dirname(__FILE__))) . '/import/' . $_GET["dir"];

    if (!file_exists($derotate_path)) {
        print("The directory '{$derotate_path}' to de-rotate does not exist.");
        exit;
    }

    # -Orientation=1: rotation is "Horizontal (normal)"
    # -overwrite_original: write the EXIF changes without creating a backup copy of the original image.
    # -preserve: preserve the files original modification date and time.
    # --printConv: use the machine-readable values for EXIF tags (e.g., 1 instead of "Horizontal (normal)").
    $command = 'exiftool -Orientation=1 -overwrite_original -preserve --printConv ' . $derotate_path;

    $output = shell_exec($command);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
    <head>
        <meta http-equiv="Content-type" content="text/html; charset=utf-8" />
        <title>Yukon Museums Database : De-rotate Image Directory</title>
        <style type="text/css">
            body {
                font-family: "Helvetica Neue", Arial, sans-serif;
            }
        </style>
    </head>
    <body>
        <img src="../../themes/default/graphics/logos/menu_logo.png" alt="">
        <h1>De-rotate Image Directory</h1>
        <p>Directory to de-rotate: <?php echo($derotate_path); ?></p>
        <p>Results of de-rotation operation: <?php echo($output); ?></p>
        <p><a href="#" onclick="window.close(); return false;">Close</a></p>
    </body>
</html>
