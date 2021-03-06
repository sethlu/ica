<?php

  require_once(DIR_ROOT . "/lib/files.php");

  function prepareFile($type, $size=0) {
    $accountId = \Session\getAccountId();
    $year = date("Y");
    $month = date("m");
    $uid = uniqid();
    $dir = "$accountId/$year/$month";
    $path = "$dir/$uid";

    if (!is_dir(DIR_ROOT . "/data/$dir")
      && !mkdir(DIR_ROOT . "/data/$dir", 0755, true)) {
      throw new Exception("Unable to create directory structure");
    }

    $file = new \ICA\Files\File;
    $file->path = $path;
    $file->type = $type;
    $file->size = $size;

    return $file;
  }

  function writeInputStreamToFile($file) {
    $size = 0;
    $input = fopen("php://input", "r");
    $output = fopen(DIR_ROOT . "/data/{$file->path}", "a+");
    while (!feof($input)) {
      $chunk = fread($input, 1024 * 1024); // 1 MB chunks
      fwrite($output, $chunk);
      $size += strlen($chunk);
    }
    fclose($input);
    fclose($output);
    return $size;
  }

  if (handle("files")) switch ($REQUEST_METHOD) {

    case "POST": \Session\requireVerification();

      if (!empty($_SERVER["HTTP_X_UPLOAD_CONTENT_TYPE"]) &&
        !empty($_SERVER["HTTP_X_UPLOAD_CONTENT_LENGTH"])) {
        // Start chunked file upload

        $type = $_SERVER["HTTP_X_UPLOAD_CONTENT_TYPE"];

        $file = prepareFile($type, (int)$_SERVER["HTTP_X_UPLOAD_CONTENT_LENGTH"]);

        $fileId = \ICA\Files\insertFile($file);
        respondJSON($fileId);

      } else {
        // Upload single file

        $type = $_SERVER["CONTENT_TYPE"];

        $file = prepareFile($type);
        $file->size = writeInputStreamToFile($file);
      }

      $fileId = \ICA\Files\insertFile($file);
      respondJSON($fileId);

      break;

  } elseif (list($fileId) = handle("files/{i}")) switch ($REQUEST_METHOD) {

    case "GET":

      $file = \ICA\Files\getFile($fileId);
      if (!$file) throw new Exception("File not exist");
      respondJSON($file);

      break;

    case "PUT":

      $file = \ICA\Files\getFile($fileId);
      if (!$file) throw new Exception("File not exist");

      if (file_exists(DIR_ROOT . "/data/{$file->path}")) {
        $size = filesize(DIR_ROOT . "/data/{$file->path}");
      }
      if (empty($size)) $size = 0;
      if ($size > $file->size) throw new Exception("File oversized on server");

      if ($size < $file->size) {
        if (empty($_SERVER['HTTP_CONTENT_RANGE'])) {
          throw new Exception("Content-Range unspecified");
        }
        preg_match('/bytes (\d*)-(\d*)\/(\d*)/', $_SERVER['HTTP_CONTENT_RANGE'], $matches);
        if (
          (int)$matches[1] != $size || // Start byte mismatch
          (int)$matches[1] > (int)$matches[2] || // Invalid range
          (int)$matches[2] + 1 - (int)$matches[1]
            != (int)$_SERVER['CONTENT_LENGTH'] || // Content length mismatch
          (int)$matches[2] >= (int)$matches[3] || // Range overflow
          (int)$matches[3] != $file->size // Request total file size mismatch
        ) {
          throw new Exception("Invalid request");
        }

        // Write to file
        $size += writeInputStreamToFile($file);
      }

      // Check if incomplete
      if ($size < $file->size) {
        $byteLast = max(0, $size - 1);
        respondHeaderResponseCode(308, "Upload Incomplete");
        header("Range: 0-{$byteLast}");
      }

      exit();
      break;

  }

?>
