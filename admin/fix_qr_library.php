<?php
// Run this file once to fix the phpqrcode library for PHP 8
// Access: http://localhost/BRITE/admin/fix_qr_library.php

$file = __DIR__ . '/qrcode/phpqrcode.php';

if (!file_exists($file)) {
    die("File not found: " . $file);
}

echo "Patching: " . $file . "<br>";

$content = file_get_contents($file);

// All GD functions that need to be replaced (case-sensitive - uppercase to lowercase)
$replacements = [
    // Image creation functions
    'ImageCreate(' => 'imagecreate(',
    'ImageCreateTrueColor(' => 'imagecreatetruecolor(',
    'ImageCreateFromString(' => 'imagecreatefromstring(',
    'ImageCreateFromJPEG(' => 'imagecreatefromjpeg(',
    'ImageCreateFromPNG(' => 'imagecreatefrompng(',
    'ImageCreateFromGIF(' => 'imagecreatefromgif(',
    'ImageCreateFromWBMP(' => 'imagecreatefromwbmp(',
    
    // Drawing functions
    'ImageSetPixel(' => 'imagesetpixel(',
    'ImageLine(' => 'imageline(',
    'ImageRectangle(' => 'imagerectangle(',
    'ImageFilledRectangle(' => 'imagefilledrectangle(',
    'ImageArc(' => 'imagearc(',
    'ImageFilledArc(' => 'imagefilledarc(',
    'ImageEllipse(' => 'imageellipse(',
    'ImageFilledEllipse(' => 'imagefilledellipse(',
    'ImagePolygon(' => 'imagepolygon(',
    'ImageFilledPolygon(' => 'imagefilledpolygon(',
    
    // Color functions
    'ImageColorAllocate(' => 'imagecolorallocate(',
    'ImageColorAllocateAlpha(' => 'imagecolorallocatealpha(',
    'ImageColorDeallocate(' => 'imagecolordeallocate(',
    'ImageColorClosest(' => 'imagecolorclosest(',
    'ImageColorExact(' => 'imagecolorexact(',
    'ImageColorSet(' => 'imagecolorset(',
    'ImageColorAt(' => 'imagecolorat(',
    'ImageColorTransparent(' => 'imagetransparent(',
    'ImageColorResolve(' => 'imagecolorresolve(',
    
    // Output functions
    'ImagePNG(' => 'imagepng(',
    'ImageJPEG(' => 'imagejpeg(',
    'ImageGIF(' => 'imagegif(',
    'ImageWBMP(' => 'imagewbmp(',
    'Image2WBMP(' => 'image2wbmp(',
    
    // Other functions
    'ImageDestroy(' => 'imagedestroy(',
    'ImageFill(' => 'imagefill(',
    'ImageCopy(' => 'imagecopy(',
    'ImageCopyResized(' => 'imagecopyresized(',
    'ImageCopyResampled(' => 'imagecopyresampled(',
    'ImageCopyMerge(' => 'imagecopymerge(',
    'ImageCopyMergeGray(' => 'imagecopymergegray(',
    'ImageString(' => 'imagestring(',
    'ImageStringUp(' => 'imagestringup(',
    'ImageChar(' => 'imagechar(',
    'ImageCharUp(' => 'imagecharup(',
    'ImageLoadFont(' => 'imageloadfont(',
    'ImageFontWidth(' => 'imagefontwidth(',
    'ImageFontHeight(' => 'imagefontheight(',
    'ImageSX(' => 'imagesx(',
    'ImageSY(' => 'imagesy(',
    'ImageTrueColorToPalette(' => 'imagetruecolortopalette(',
    'ImagePaletteCopy(' => 'imagepalettecopy(',
    'ImageSetBrush(' => 'imagesetbrush(',
    'ImageSetStyle(' => 'imagesetstyle(',
    'ImageSetThickness(' => 'imagesetthickness(',
    'ImageInterlace(' => 'imageinterlace(',
    'ImageAntiAlias(' => 'imageantialias(',
    'ImageFilter(' => 'imagefilter(',
    'ImageFlip(' => 'imageflip(',
    'ImageRotate(' => 'imagerotate(',
];

$count = 0;
foreach ($replacements as $search => $replace) {
    if (strpos($content, $search) !== false) {
        $content = str_replace($search, $replace, $content);
        $count++;
        echo "✓ Replaced: $search → $replace<br>";
    }
}

// Save the patched file
file_put_contents($file, $content);

echo "<br><strong>Total replacements: $count</strong><br>";
echo "<p style='color:green; font-weight:bold;'>Library patched successfully!</p>";
echo "<p>Now test QR generation: <a href='generate_qr.php?request_id=54'>Generate QR for request 54</a></p>";
echo "<p>Or visit: <a href='generate_qr.php?test'>Test QR Library</a></p>";
?>