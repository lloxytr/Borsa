<?php
// bist_universe.php - BIST evren listeleri

/**
 * BIST200 sembol listesi.
 * Not: Listeyi tam tutmak için bu dosyayı periyodik güncelle.
 */
function getBIST200Symbols(): array {
    $dataFile = __DIR__ . '/data/bist200.json';
    if (is_file($dataFile)) {
        $json = json_decode((string)file_get_contents($dataFile), true);
        if (is_array($json)) {
            return $json;
        }
    }

    return [
        'AKBNK' => 'Akbank',
        'ALARK' => 'Alarko Holding',
        'ARCLK' => 'Arçelik',
        'ASELS' => 'Aselsan',
        'BIMAS' => 'BİM',
        'DOHOL' => 'Doğan Holding',
        'EKGYO' => 'Emlak Konut GYO',
        'ENKAI' => 'Enka İnşaat',
        'EREGL' => 'Ereğli Demir Çelik',
        'FROTO' => 'Ford Otosan',
        'GARAN' => 'Garanti BBVA',
        'HALKB' => 'Halkbank',
        'ISCTR' => 'İş Bankası C',
        'KCHOL' => 'Koç Holding',
        'KOZAL' => 'Koza Altın',
        'MGROS' => 'Migros',
        'PETKM' => 'Petkim',
        'PGSUS' => 'Pegasus',
        'SAHOL' => 'Sabancı Holding',
        'SISE' => 'Şişe Cam',
        'TCELL' => 'Turkcell',
        'THYAO' => 'Türk Hava Yolları',
        'TOASO' => 'Tofaş Oto',
        'TUPRS' => 'Tüpraş',
        'TTKOM' => 'Türk Telekom',
        'VESBE' => 'Vestel Beyaz Eşya',
        'VAKBN' => 'Vakıfbank',
        'YKBNK' => 'Yapı Kredi Bankası'
    ];
}

/**
 * BIST evreni seçimi (varsayılan: BIST200)
 */
function getBistUniverse(): array {
    return getBIST200Symbols();
}
