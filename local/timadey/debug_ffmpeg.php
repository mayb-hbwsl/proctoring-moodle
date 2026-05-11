<?php
require_once('../../config.php');
require_login();

$path = 'C:\Users\msacc\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-8.1-full_build\bin\ffmpeg.exe';

echo "<pre>";

// Test 1: what exec returns with quotes around path (what find_ffmpeg does)
$out = []; $ret = -1;
exec('"' . $path . '" -version 2>&1', $out, $ret);
echo "Test 1 - quoted path exec ret: $ret\n";
echo "output: " . implode("\n", array_slice($out, 0, 2)) . "\n\n";

// Test 2: without quotes
$out2 = []; $ret2 = -1;
exec($path . ' -version 2>&1', $out2, $ret2);
echo "Test 2 - unquoted path exec ret: $ret2\n";
echo "output: " . implode("\n", array_slice($out2, 0, 2)) . "\n\n";

// Test 3: via cmd /c
$out3 = []; $ret3 = -1;
exec('cmd /c "' . $path . '" -version 2>&1', $out3, $ret3);
echo "Test 3 - cmd /c exec ret: $ret3\n";
echo "output: " . implode("\n", array_slice($out3, 0, 2)) . "\n\n";

// Test 4: bare ffmpeg
$out4 = []; $ret4 = -1;
exec('ffmpeg -version 2>&1', $out4, $ret4);
echo "Test 4 - bare ffmpeg ret: $ret4\n";
echo "output: " . implode("\n", array_slice($out4, 0, 2)) . "\n\n";

echo "exec disabled? " . (function_exists('exec') ? 'NO' : 'YES') . "\n";
echo "</pre>";
