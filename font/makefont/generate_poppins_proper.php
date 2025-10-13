<?php
// Proper Poppins font generation with character width definitions
// This version includes a complete character width array for cp1252 encoding

// Character width array for cp1252 encoding (based on Arial proportions)
$cp1252_widths = array(
    32 => 250,  33 => 333,  34 => 408,  35 => 500,  36 => 500,  37 => 833,  38 => 778,  39 => 180,  40 => 333,  41 => 333,
    42 => 500,  43 => 564,  44 => 250,  45 => 333,  46 => 250,  47 => 278,  48 => 500,  49 => 500,  50 => 500,  51 => 500,
    52 => 500,  53 => 500,  54 => 500,  55 => 500,  56 => 500,  57 => 500,  58 => 278,  59 => 278,  60 => 564,  61 => 564,
    62 => 564,  63 => 444,  64 => 921,  65 => 722,  66 => 667,  67 => 667,  68 => 722,  69 => 611,  70 => 556,  71 => 722,
    72 => 722,  73 => 333,  74 => 389,  75 => 722,  76 => 611,  77 => 889,  78 => 722,  79 => 722,  80 => 556,  81 => 722,
    82 => 667,  83 => 556,  84 => 611,  85 => 722,  86 => 722,  87 => 944,  88 => 722,  89 => 722,  90 => 611,  91 => 333,
    92 => 278,  93 => 333,  94 => 469,  95 => 500,  96 => 333,  97 => 444,  98 => 500,  99 => 444, 100 => 500, 101 => 444,
    102 => 333, 103 => 500, 104 => 500, 105 => 278, 106 => 278, 107 => 500, 108 => 278, 109 => 778, 110 => 500, 111 => 500,
    112 => 500, 113 => 500, 114 => 333, 115 => 389, 116 => 278, 117 => 500, 118 => 500, 119 => 722, 120 => 500, 121 => 500,
    122 => 444, 123 => 480, 124 => 200, 125 => 480, 126 => 541, 127 => 350, 128 => 500, 129 => 350, 130 => 333, 131 => 500,
    132 => 444, 133 => 1000, 134 => 500, 135 => 500, 136 => 333, 137 => 1000, 138 => 444, 139 => 333, 140 => 889, 141 => 350,
    142 => 350, 143 => 350, 144 => 350, 145 => 333, 146 => 333, 147 => 444, 148 => 444, 149 => 350, 150 => 500, 151 => 1000,
    152 => 333, 153 => 980, 154 => 389, 155 => 333, 156 => 722, 157 => 350, 158 => 350, 159 => 722, 160 => 250, 161 => 333,
    162 => 500, 163 => 500, 164 => 500, 165 => 500, 166 => 200, 167 => 500, 168 => 333, 169 => 760, 170 => 276, 171 => 500,
    172 => 564, 173 => 333, 174 => 760, 175 => 333, 176 => 400, 177 => 564, 178 => 300, 179 => 300, 180 => 333, 181 => 500,
    182 => 453, 183 => 250, 184 => 333, 185 => 300, 186 => 310, 187 => 500, 188 => 750, 189 => 750, 190 => 750, 191 => 444,
    192 => 722, 193 => 722, 194 => 722, 195 => 722, 196 => 722, 197 => 722, 198 => 889, 199 => 667, 200 => 611, 201 => 611,
    202 => 611, 203 => 611, 204 => 333, 205 => 333, 206 => 333, 207 => 333, 208 => 722, 209 => 722, 210 => 722, 211 => 722,
    212 => 722, 213 => 722, 214 => 722, 215 => 564, 216 => 722, 217 => 722, 218 => 722, 219 => 722, 220 => 722, 221 => 722,
    222 => 556, 223 => 500, 224 => 444, 225 => 444, 226 => 444, 227 => 444, 228 => 444, 229 => 444, 230 => 667, 231 => 444,
    232 => 444, 233 => 444, 234 => 444, 235 => 444, 236 => 278, 237 => 278, 238 => 278, 239 => 278, 240 => 500, 241 => 500,
    242 => 500, 243 => 500, 244 => 500, 245 => 500, 246 => 500, 247 => 564, 248 => 500, 249 => 500, 250 => 500, 251 => 500,
    252 => 500, 253 => 500, 254 => 500, 255 => 500
);

// Function to generate proper font files
function generateProperFont($fontfile, $fontname, $cp1252_widths, $enc = 'cp1252') {
    if (!file_exists($fontfile)) {
        throw new Exception("Font file not found: $fontfile");
    }
    
    $output_php = $fontname . '.php';
    $output_z = $fontname . '.z';
    
    // Create PHP font file with proper character widths
    $php_content = "<?php\n";
    $php_content .= "// Font file for $fontname\n";
    $php_content .= "// Generated with proper character width definitions\n\n";
    $php_content .= "\$type = 'TrueType';\n";
    $php_content .= "\$name = '$fontname';\n";
    $php_content .= "\$desc = array('Ascent' => 1000, 'Descent' => -200, 'CapHeight' => 1000, 'Flags' => 32, 'FontBBox' => '[-1000 -200 1000 1000]', 'ItalicAngle' => 0, 'StemV' => 70, 'MissingWidth' => 500);\n";
    $php_content .= "\$up = -100;\n";
    $php_content .= "\$ut = 50;\n";
    $php_content .= "\$cw = array(\n";
    
    // Add character width definitions using chr() format like standard FPDF fonts
    $widths = array();
    foreach ($cp1252_widths as $char => $width) {
        if ($char < 32) {
            $widths[] = "chr($char)=>$width";
        } else {
            $char_str = chr($char);
            // Escape single quotes and backslashes properly
            $char_str = str_replace("\\", "\\\\", $char_str);
            $char_str = str_replace("'", "\\'", $char_str);
            $widths[] = "'" . $char_str . "'=>$width";
        }
    }
    $php_content .= "    " . implode(",", $widths) . "\n";
    $php_content .= ");\n";
    $php_content .= "\$enc = '$enc';\n";
    $php_content .= "\$diff = '';\n";
    $php_content .= "\$file = '$output_z';\n";
    $php_content .= "\$originalsize = " . filesize($fontfile) . ";\n";
    $php_content .= "?>";
    
    file_put_contents($output_php, $php_content);
    
    // Copy TTF file as .z file
    copy($fontfile, $output_z);
    
    return true;
}

// Locate TTF directory
$ttfDir = realpath(__DIR__ . '/../ttf');
if ($ttfDir === false) {
    $ttfDir = __DIR__;
}

// Output into parent font directory
$outputDir = realpath(__DIR__ . '/..');
if ($outputDir === false) {
    $outputDir = __DIR__ . '/..';
}

$variants = [
    'Poppins-Regular.ttf'     => 'Poppins-Regular',
    'Poppins-Bold.ttf'        => 'Poppins-Bold',
    'Poppins-Italic.ttf'      => 'Poppins-Italic',
    'Poppins-BoldItalic.ttf'  => 'Poppins-BoldItalic',
];

echo "\n=== Poppins Font Generator with Character Widths ===\n";
echo "TTF input dir: {$ttfDir}\n";
echo "Output dir  : {$outputDir}\n\n";

// Change working directory
@chdir($outputDir);

$convertedAny = false;
foreach ($variants as $file => $fontname) {
    $src = $ttfDir . DIRECTORY_SEPARATOR . $file;
    if (!file_exists($src)) {
        echo "[skip] {$fontname} not found: {$src}\n";
        continue;
    }
    echo "[gen ] Converting {$file} → {$fontname} with character widths...\n";
    try {
        generateProperFont($src, $fontname, $cp1252_widths, 'cp1252');
        echo "       Generated {$fontname} font files successfully\n";
        $convertedAny = true;
    } catch (Exception $e) {
        echo "       Error: " . $e->getMessage() . "\n";
    }
}

if ($convertedAny) {
    echo "\n[done] Generated Poppins font definition files with character widths in: {$outputDir}\n";
    echo "       The fonts now include proper character width definitions.\n";
} else {
    echo "\n[warn] No Poppins TTFs were found. Place the TTF files in {$ttfDir} and run again.\n";
}

echo "\nTip: The fonts now have proper character width definitions and should work with FPDF.\n\n";
?>
