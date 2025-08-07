# Meta Editor in Bulk Pro 🚀

Un plugin WordPress professionale per la gestione avanzata in blocco dei meta dati SEO con supporto multilingua e ottimizzazione immagini.

## ✨ Caratteristiche Principali

### 🔧 Gestione SEO Avanzata
- **Editing in blocco** di meta title, description e focus keyword
- **Compatibilità completa** con Yoast SEO, Rank Math, All in One SEO, SEOPress
- **Modalità autonoma** integrata per chi non usa plugin SEO
- **Anteprima Google realistica** con preview desktop e mobile
- **Analisi SEO in tempo reale** con suggerimenti migliorativi

### 🌍 Supporto Multilingua
- **WPML** - Supporto completo per siti multilingua
- **Polylang** - Gestione avanzata delle traduzioni
- **Filtri per lingua** in tutte le sezioni
- **Statistiche separate** per ogni lingua
- **Export/Import** con supporto multilingua

### 📊 Dashboard e Analytics
- **Grafici interattivi** con storico delle ottimizzazioni
- **Statistiche dettagliate** per tipo di contenuto
- **Report settimanali** automatici via email
- **Monitoraggio progressi** in tempo reale

### 🖼️ Ottimizzazione Immagini
- **Compressione automatica** JPEG, PNG, WebP
- **Generazione testo alternativo** per accessibilità
- **Ridimensionamento intelligente** 
- **Ottimizzazione in blocco** 
- **Statistiche dettagliate** sulle immagini

### 📝 Gestione Contenuti
- **Post** - Articoli e pagine
- **WooCommerce** - Prodotti e categorie
- **Tassonomie** - Categorie e tag personalizzati
- **Tipi di post personalizzati**
- **Export/Import CSV** completo

## 🛠️ Requisiti Tecnici

- **WordPress**: 5.0+
- **PHP**: 8.0+
- **MySQL**: 5.6+
- **Memoria**: 128MB+ (consigliati 256MB)

## 📦 Installazione

### Metodo 1: Upload Manuale
1. Scarica il plugin da GitHub
2. Vai in `WordPress Admin > Plugin > Aggiungi nuovo > Carica plugin`
3. Seleziona il file ZIP e installa
4. Attiva il plugin

### Metodo 2: FTP
1. Estrai i file nella cartella `/wp-content/plugins/meta-editor-in-bulk-pro/`
2. Attiva il plugin dal pannello WordPress

## 🚀 Guida Rapida

### Prima configurazione
1. Vai su **Meta Editor** nel menu admin
2. Il plugin rileverà automaticamente il tuo plugin SEO
3. Scegli il tipo di contenuto da ottimizzare
4. Inizia a modificare i meta dati!

### Ottimizzazione immagini
1. Vai su **Meta Editor > SEO Immagini**
2. Configura le impostazioni di ottimizzazione
3. Seleziona le immagini da ottimizzare
4. Avvia il processo automatico

### Supporto multilingua
- Il plugin rileva automaticamente WPML o Polylang
- Usa i filtri lingua per lavorare su contenuti specifici
- Le statistiche sono separate per ogni lingua

## 📸 Screenshots

### Dashboard Principale
<img width="1806" height="795" alt="image" src="https://github.com/user-attachments/assets/08009b30-5611-4789-a027-6d5a9b0e6abd" />

### Gestione Immagini
<img width="1806" height="795" alt="image" src="https://github.com/user-attachments/assets/e709eefc-2942-49d6-b06d-d2eaae78a5cf" />


### Impostazioni
<img width="1806" height="795" alt="image" src="https://github.com/user-attachments/assets/f92a1522-9d24-4dd5-a742-0499859230c4" />


### Email con Report Settimanale
<img width="1220" height="716" alt="Schermata del 2025-08-07 23-21-46" src="https://github.com/user-attachments/assets/bf5939f9-7b92-47bc-a233-e8864d3fbcc3" />

### Nota:
- Dalle Impostazioni si può Forzare il Cron Job per l'invio della mail
- La mail con il report potrebbe arrivare in spam


## 🔧 Configurazione Avanzata

### Plugin SEO Supportati
```php
// Il plugin rileva automaticamente:
- Yoast SEO
- Rank Math
- All in One SEO
- SEOPress
- Modalità integrata (senza plugin esterni)
```

### Personalizzazioni
```php
// Filtri disponibili per sviluppatori
add_filter('meb_localize_script_data', 'custom_meb_data');
add_filter('meb_seo_meta_keys', 'custom_meta_keys');
add_action('meb_after_bulk_update', 'custom_after_update');
```

## 📋 Compatibilità

### Plugin Testati
- ✅ **Yoast SEO** (tutte le versioni)
- ✅ **Rank Math** (2.0+)
- ✅ **All in One SEO** (4.0+)
- ✅ **SEOPress** (5.0+)
- ✅ **WPML** (4.0+)
- ✅ **Polylang** (3.0+)
- ✅ **WooCommerce** (6.0+)

### Temi Testati
- ✅ **Astra**
- ✅ **GeneratePress**
- ✅ **OceanWP**
- ✅ **Storefront**
- ✅ **Twenty Twenty-Four**

## 🐛 Risoluzione Problemi

### Problemi Comuni

**Il grafico non si carica**
```
Soluzione: Controlla la console browser per errori JavaScript
Verifica connessione CDN per Chart.js e Litepicker
```

**Ottimizzazione immagini fallisce**
```
Soluzione: Aumenta memory_limit PHP a 256MB+
Verifica permessi cartella uploads
Controlla estensioni GD/ImageMagick
```

**Dati multilingua non visibili**
```
Soluzione: Verifica attivazione WPML/Polylang
Controlla configurazione lingue
Rigenera dati dalle impostazioni
```

## 🔄 Changelog

### Version 2.0.x
- 🐛 **Is not a bug is a feature**: qualsiasi cosa che può essere considerata un bug, mi dispiace non lo è 😈
In realtà si tratta molto probabilmente di una funzione non più implementata per pigrizia oppure perché per il momento questa versione va benissimo così.

### Version 2.0.2 (Current)
- ✨ **Nuovo**: Modulo ottimizzazione immagini completo
- ✨ **Nuovo**: Supporto multilingua WPML/Polylang
- ✨ **Nuovo**: Anteprima Google realistica
- 🐛 **Fix**: Migliorata gestione memoria
- 🚀 **Performance**: Ottimizzazioni database
- 🎨 **UI**: Interfaccia completamente ridisegnata

### Version 1.5.1
- ✨ **Nuovo**: Supporto WooCommerce
- ✨ **Nuovo**: Export/Import CSV
- 🐛 **Fix**: Compatibilità PHP 8.0+

### Version 1.0.0
- 🎉 **Release iniziale**
- 📝 Gestione base meta dati
- 📊 Dashboard con statistiche

## 🤝 Contribuire

Contributi, issues e feature requests sono benvenuti!

1. **Fork** il progetto
2. Crea il tuo **feature branch** (`git checkout -b feature/AmazingFeature`)
3. **Commit** le modifiche (`git commit -m 'Add some AmazingFeature'`)
4. **Push** al branch (`git push origin feature/AmazingFeature`)
5. Apri una **Pull Request**

### Linee Guida
- Segui gli standard di codifica WordPress
- Testa su PHP 8.0+ e WordPress 5.0+
- Documenta le nuove funzionalità
- Mantieni compatibilità backwards

## 🧪 Testing

```bash
# Test PHP
composer install
vendor/bin/phpunit

# Test JavaScript
npm install
npm test

# Test WordPress
wp-env start
npm run test:e2e
```

## 📄 Licenza

**GPL v2 or later** - Usa, modifica e distribuisci liberamente!

Questo progetto è licenziato sotto la GPL v2+ - vedi il file [LICENSE](LICENSE) per i dettagli.

## 👨‍💻 Autore

**Flavius Florin Harabor**  
🌐 [2088.it](https://2088.it/io-nerd/)  
💼 Sviluppatore WordPress Freelance
💼 Consulente Web Marketing e Imperatore di Telegram

## 💰 Donazioni

Se questo progetto ti è stato utile per il tuo lavoro, considera una piccola donazione:

[Ko-fi](https://ko-fi.com/insidetelegramproject)

Le donazioni aiutano a mantenere il progetto attivo e a sviluppare nuove funzionalità!

## ⭐ Se ti piace il progetto

- **Lascia una stella** su GitHub ⭐
- **Condividi** con altri sviluppatori WordPress
- **Seguimi** sui social per aggiornamenti
- **Scrivi una recensione** se usi il plugin

## 📫 Contatti

Hai domande? Vuoi collaborare? Contattami!

- [Telegram](https://t.me/ErBoss88)
- [Instagram](https://instagram.com/flaviusharabor/)
- [Twitter](https://twitter.com/FlaviusHarabor)
- [LinkedIn](https://www.linkedin.com/in/flaviusflorinharabor/)
- [YouTube](http://www.youtube.com/c/FlaviusFlorinHarabor)

---

### 🏷️ Tags

`wordpress` `seo` `meta-tags` `bulk-editor` `multilingua` `wpml` `polylang` `yoast` `rankmath` `woocommerce` `image-optimization` `webp` `accessibility` `php8` `javascript` `react`
