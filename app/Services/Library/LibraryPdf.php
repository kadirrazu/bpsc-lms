<?php

namespace App\Services\Library;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Language\LanguageToFont;
use Mpdf\Mpdf;

class LibraryPdf
{
    public function make(array $options = []): Mpdf
    {
        $temp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($temp, 0700);
        return new Mpdf(array_replace([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'L',
            'margin_top' => 20, 'margin_bottom' => 17, 'margin_left' => 10, 'margin_right' => 10,
            'tempDir' => $temp,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], public_path('fonts')],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'nikosh' => ['R' => 'Nikosh.ttf', 'useOTL' => 0xFF],
            ],
            'autoScriptToLang' => true, 'autoLangToFont' => true,
            'languageToFont' => new class extends LanguageToFont
            {
                public function getLanguageOptions($llcc, $adobeCJK)
                {
                    if (str_starts_with((string) $llcc, 'bn')) {
                        return [false, 'nikosh'];
                    }

                    return parent::getLanguageOptions($llcc, $adobeCJK);
                }
            },
            'default_font' => 'dejavusans', 'default_font_size' => 9,
        ], $options));
    }
}
