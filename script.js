jQuery(function($) {
    'use strict';
    
    if (typeof mebData === 'undefined') { 
        console.error('MEB: Dati di localizzazione non trovati.'); 
        return; 
    }

    console.log('MEB: Inizializzazione plugin...');
    
    // NUOVO: Log informazioni multilingua
    if (mebData.multilang && mebData.multilang.enabled) {
        console.log('MEB: Plugin multilingua rilevato:', mebData.multilang.plugin);
        console.log('MEB: Lingua corrente:', mebData.multilang.current);
        console.log('MEB: Lingue disponibili:', Object.keys(mebData.multilang.languages));
    }
    
    // NUOVO: Log informazioni immagini
    if (mebData.images) {
        console.log('MEB: Modulo ottimizzazione immagini attivo');
    }

    // ===================================================================
    // 1. LOGICA IMPORT/EXPORT (INVARIATA)
    // ===================================================================
    const importContainer = $('#meb-import-form-container');
    const tableContainer = $('.meb-table-container');
    
    $('#meb-import-button').on('click', function() { 
        importContainer.slideDown(300); 
        tableContainer.hide(); 
    });
    
    $('#meb-cancel-import').on('click', function() { 
        importContainer.slideUp(300); 
        tableContainer.show(); 
    });

    // ===================================================================
    // 2. CARICAMENTO LIBRERIE CON FALLBACK (INVARIATO)
    // ===================================================================
    function loadLibraryFallback(libName, url, checkFunction) {
        return new Promise((resolve, reject) => {
            if (checkFunction()) {
                resolve();
                return;
            }
            
            console.log(`MEB: Caricamento fallback per ${libName}...`);
            const script = document.createElement('script');
            script.src = url;
            script.onload = () => {
                console.log(`MEB: ${libName} caricato con successo (fallback)`);
                setTimeout(resolve, 100);
            };
            script.onerror = () => {
                console.error(`MEB: Errore nel caricamento ${libName} (fallback)`);
                reject();
            };
            document.head.appendChild(script);
        });
    }

    // ===================================================================
    // 3. GESTIONE MULTILINGUA - SINCRONIZZAZIONE FILTRI (INVARIATA)
    // ===================================================================
    
    // Sincronizza i filtri lingua tra grafico e tabella
    $('#meb_language').on('change', function() {
        const selectedLang = $(this).val();
        console.log('MEB: Cambio lingua tabella a:', selectedLang);
        
        // Sincronizza con il selettore del grafico se esiste
        const chartLangSelector = $('#meb-chart-language');
        if (chartLangSelector.length && chartLangSelector.val() !== selectedLang) {
            chartLangSelector.val(selectedLang);
            
            // Aggiorna il grafico se non ci sono date selezionate
            const datePickerValue = $('#meb-date-picker').val();
            if (!datePickerValue && typeof window.updateChart === 'function') {
                console.log('MEB: Aggiornamento grafico per nuova lingua');
                setTimeout(() => {
                    window.updateChart();
                }, 300);
            }
        }
    });
    
    // Gestione cambio lingua per il grafico
    $(document).on('change', '#meb-chart-language', function() {
        const selectedLang = $(this).val();
        console.log('MEB: Cambio lingua grafico a:', selectedLang);
        
        // Sincronizza con il filtro della tabella se esiste
        const tableLangSelector = $('#meb_language');
        if (tableLangSelector.length && tableLangSelector.val() !== selectedLang) {
            tableLangSelector.val(selectedLang);
        }
        
        // Aggiorna il grafico con la nuova lingua
        if (typeof window.updateChart === 'function') {
            // Se c'è un range di date selezionato, ricarica i dati storici
            const datePickerValue = $('#meb-date-picker').val();
            if (datePickerValue && datePickerValue.includes(' - ')) {
                const dates = datePickerValue.split(' - ');
                loadHistoricalData(dates[0], dates[1], selectedLang);
            } else {
                // Altrimenti mostra il grafico attuale
                window.updateChart();
            }
        }
    });

    // ===================================================================
    // 4. GRAFICO CON SUPPORTO MULTILINGUA (INVARIATO)
    // ===================================================================
    let historyChart = null;

    function initializeChart() {
        console.log('MEB: Inizializzazione grafico...');
        
        const ctx = $('#mebHistoryChart');
        
        // Funzione per caricare dati storici con supporto multilingua
        function loadHistoricalData(startDate, endDate, language = null) {
            console.log('MEB: Caricamento dati storici:', startDate, endDate, 'lingua:', language);
            
            const apiUrl = new URL(mebData.history_api_url);
            apiUrl.searchParams.append('start_date', startDate);
            apiUrl.searchParams.append('end_date', endDate);
            
            // NUOVO: Aggiungi parametro lingua se specificato
            if (language) {
                apiUrl.searchParams.append('language', language);
            } else if (mebData.multilang && mebData.multilang.enabled) {
                // Usa la lingua selezionata nel grafico o quella corrente
                const chartLang = $('#meb-chart-language').val() || mebData.multilang.current;
                apiUrl.searchParams.append('language', chartLang);
            }

            const chartContainer = ctx.parent();
            chartContainer.find('.chart-loading').remove();
            
            // NUOVO: Messaggio di caricamento personalizzato per multilingua
            let loadingMessage = 'Caricamento storico...';
            if (mebData.multilang && mebData.multilang.enabled && language && language !== 'all') {
                const langInfo = mebData.multilang.languages[language];
                if (langInfo) {
                    const flag = langInfo.flag ? `<img src="${langInfo.flag}" style="width:16px;height:12px;margin-right:6px;" />` : '';
                    loadingMessage = `${flag}Caricamento dati per ${langInfo.name}...`;
                }
            }
            
            chartContainer.append(`<div class="chart-loading" style="text-align: center; padding: 20px;">${loadingMessage}</div>`);

            $.ajax({
                url: apiUrl.toString(),
                method: 'GET',
                beforeSend: function(xhr) { 
                    xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce); 
                },
                timeout: 10000,
                success: function(data) {
                    $('.chart-loading').remove();
                    
                    if (data && Array.isArray(data) && data.length > 0) {
                        showHistoricalChart(data, startDate, endDate, language);
                    } else {
                        // NUOVO: Messaggio personalizzato per multilingua
                        let noDataMessage = `
                            <div style="text-align: center; padding: 40px; color: #6b7280;">
                                <div style="font-size: 48px; margin-bottom: 20px;">📅</div>
                                <h3 style="margin: 0 0 10px 0; color: #374151;">Nessun Dato Storico</h3>
                                <p style="margin: 0 0 10px 0;">Dal ${startDate} al ${endDate}</p>
                        `;
                        
                        // Aggiungi info lingua se multilingua è attivo
                        if (mebData.multilang && mebData.multilang.enabled && language && language !== 'all') {
                            const langInfo = mebData.multilang.languages[language];
                            if (langInfo) {
                                const flag = langInfo.flag ? `<img src="${langInfo.flag}" style="width:20px;height:15px;margin-right:6px;" />` : '';
                                noDataMessage += `<p style="font-size: 14px; color: #6366f1; margin-bottom: 10px;">${flag}Lingua: ${langInfo.name} (${language})</p>`;
                            }
                        }
                        
                        noDataMessage += `
                                <p style="font-size: 14px; color: #9ca3af; margin-bottom: 20px;">I dati storici vengono salvati settimanalmente.</p>
                                <button id="back-to-current" class="button button-secondary">Torna al Grafico Attuale</button>
                            </div>
                        `;
                        
                        chartContainer.html(noDataMessage);
                        
                        $('#back-to-current').on('click', function() {
                            $('#meb-date-picker').val('');
                            showCurrentStateChart();
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('.chart-loading').remove();
                    console.error('MEB: Errore nel caricamento dati storici:', error);
                    showCurrentStateChart();
                }
            });
        }

        // SEMPRE mostra il grafico a barre con lo stato attuale (MODIFICATO per multilingua)
        function showCurrentStateChart() {
            console.log('MEB: Creazione grafico stato attuale...');
            
            const postTypes = [];
            const optimizedData = [];
            const notOptimizedData = [];
            const labels = [];

            // Leggi i dati dalla sidebar esistente
            $('.meb-stats-container li').each(function() {
                const text = $(this).find('strong').text();
                const statsText = $(this).find('small').text();
                
                if (text && statsText) {
                    const match = statsText.match(/(\d+)\s*\/\s*(\d+)/);
                    if (match) {
                        const optimized = parseInt(match[1]);
                        const total = parseInt(match[2]);
                        const notOptimized = total - optimized;
                        
                        labels.push(text);
                        optimizedData.push(optimized);
                        notOptimizedData.push(notOptimized);
                    }
                }
            });

            if (labels.length === 0) {
                ctx.parent().html('<p style="text-align: center; padding: 40px; color: #666;">Nessun dato disponibile</p>');
                return;
            }

            // Distruggi il grafico esistente se presente
            if (historyChart) {
                historyChart.destroy();
                historyChart = null;
            }

            // NUOVO: Titolo dinamico con info lingua
            let chartTitle = 'Riepilogo Ottimizzazione';
            if (mebData.multilang && mebData.multilang.enabled) {
                const currentLang = $('#meb-chart-language').val() || mebData.multilang.current;
                if (currentLang && currentLang !== 'all') {
                    const langInfo = mebData.multilang.languages[currentLang];
                    if (langInfo) {
                        chartTitle += ` - ${langInfo.name} (${currentLang.toUpperCase()})`;
                    }
                } else if (currentLang === 'all') {
                    chartTitle += ' - Tutte le Lingue';
                }
            }

            historyChart = new Chart(ctx[0], {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Ottimizzati',
                            data: optimizedData,
                            backgroundColor: '#22c55e',
                            borderColor: '#16a34a',
                            borderWidth: 1
                        },
                        {
                            label: 'Non Ottimizzati', 
                            data: notOptimizedData,
                            backgroundColor: '#ef4444',
                            borderColor: '#dc2626',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: chartTitle
                        }
                    },
                    scales: {
                        x: {
                            stacked: true
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true
                        }
                    }
                }
            });

            console.log('MEB: Grafico aggiornato con supporto multilingua');
        }

        function showHistoricalChart(data, startDate, endDate, language = null) {
            // Crea un grafico a linee semplice con percentuali
            const labels = [...new Set(data.map(item => item.record_date))].sort();
            const postTypes = [...new Set(data.map(item => item.post_type))];
            
            const colors = ['#4A90E2', '#50E3C2', '#F5A623', '#BD10E0', '#B8E986'];
            
            const datasets = postTypes.map((type, index) => {
                const color = colors[index % colors.length];
                
                return {
                    label: type.charAt(0).toUpperCase() + type.slice(1) + ' (% Ottimizzati)',
                    data: labels.map(label => {
                        const record = data.find(d => d.record_date === label && d.post_type === type);
                        return record ? (record.total_posts > 0 ? Math.round((record.optimized_posts / record.total_posts) * 100) : 0) : 0;
                    }),
                    borderColor: color, 
                    backgroundColor: color + '33', 
                    fill: false, 
                    tension: 0.3
                };
            });

            if (historyChart) {
                historyChart.destroy();
                historyChart = null;
            }

            // NUOVO: Titolo con informazioni lingua
            let chartTitle = `Storico Ottimizzazione (${startDate} → ${endDate})`;
            if (mebData.multilang && mebData.multilang.enabled && language && language !== 'all') {
                const langInfo = mebData.multilang.languages[language];
                if (langInfo) {
                    chartTitle += ` - ${langInfo.name}`;
                }
            } else if (language === 'all') {
                chartTitle += ' - Tutte le Lingue';
            }

            historyChart = new Chart(ctx[0], { 
                type: 'line', 
                data: { 
                    labels: labels.map(date => {
                        const d = new Date(date);
                        return d.toLocaleDateString('it-IT', {day: 'numeric', month: 'short'});
                    }), 
                    datasets: datasets 
                }, 
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: chartTitle
                        }
                    },
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            ticks: { 
                                callback: value => value + '%'
                            } 
                        }
                    }
                } 
            });
        }

        // Inizializza il date picker con supporto multilingua
        function initializeDatePicker() {
            if (typeof Litepicker === 'undefined') {
                console.warn('MEB: Litepicker non disponibile');
                $('#meb-date-picker').prop('disabled', true).attr('placeholder', 'Date picker non disponibile');
                return;
            }

            console.log('MEB: Inizializzazione Litepicker...');
            
            const picker = new Litepicker({
                element: document.getElementById('meb-date-picker'),
                singleMode: false,
                format: 'YYYY-MM-DD',
                autoApply: true,
                numberOfMonths: 2,
                numberOfColumns: 2,
                splitView: true,
                setup: (picker) => {
                    picker.on('selected', (date1, date2) => {
                        if (date1 && date2) {
                            console.log('MEB: Date selezionate, carico storico multilingua');
                            const selectedLang = $('#meb-chart-language').val() || mebData.multilang.current || 'all';
                            loadHistoricalData(date1.format('YYYY-MM-DD'), date2.format('YYYY-MM-DD'), selectedLang);
                        }
                    });
                    
                    picker.on('clear', () => {
                        console.log('MEB: Date pulite, torno al grafico attuale');
                        showCurrentStateChart();
                    });
                }
            });
        }

        // Esponi le funzioni per aggiornamenti
        window.updateChart = showCurrentStateChart;
        window.loadHistoricalData = loadHistoricalData;
        
        // Inizializza tutto
        initializeDatePicker();
        showCurrentStateChart(); // SEMPRE inizia con il grafico attuale
    }

    // ===================================================================
    // 5. CONTROLLO CARICAMENTO LIBRERIE (INVARIATO)
    // ===================================================================
    async function waitForLibraries() {
        try {
            if (typeof Chart === 'undefined') {
                await loadLibraryFallback('Chart.js', mebData.chart_js_url, () => typeof Chart !== 'undefined');
            }
            
            if (typeof Litepicker === 'undefined') {
                await loadLibraryFallback('Litepicker', mebData.litepicker_url, () => typeof Litepicker !== 'undefined');
            }
            
            console.log('MEB: Librerie caricate, inizializzo grafico');
            setTimeout(initializeChart, 200);
            
        } catch (error) {
            console.error('MEB: Errore nel caricamento delle librerie:', error);
            $('#mebHistoryChart').parent().html(`
                <div style="text-align: center; padding: 40px; color: #d63638;">
                    <h4>⚠️ Errore di Caricamento</h4>
                    <p>Controlla la connessione internet e ricarica la pagina.</p>
                    <button onclick="location.reload()" class="button button-secondary">Ricarica</button>
                </div>
            `);
        }
    }

    // Solo inizializza il grafico se siamo sulla pagina principale
    if (window.location.href.includes('meta-editor-in-bulk') && !window.location.href.includes('meb-seo-images') && !window.location.href.includes('meb-settings')) {
        waitForLibraries();
    }

    // ===================================================================
    // 6. GESTIONE TABELLA E ANTEPRIMA GOOGLE REALISTICA (INVARIATA)
    // ===================================================================
    let modifiedRows = new Set();
    const limits = mebData.limits;
    const seoVars = mebData.seo_vars;
    const powerWords = ['guida', 'segreti', 'veloce', 'migliore', 'gratis', 'facile', 'subito', 'completa', 'definitiva', 'scopri'];

    function parseSeoVars(str) { 
        if (!str) return ''; 
        return str.replace(/%%sitename%%/g, seoVars.sitename)
                 .replace(/%%sep%%/g, ` ${seoVars.sep} `)
                 .replace(/\s+/g, ' ')
                 .trim(); 
    }

    // ===================================================================
    // 7. AGGIORNAMENTO ANTEPRIMA GOOGLE REALISTICA (INVARIATA)
    // ===================================================================
    function updateGooglePreview(mainRow) {
        const drawer = mainRow.next('.meb-drawer-row');
        const preview = drawer.find('.google-serp-preview');
        
        // Raccogli i dati dai campi
        const keyword = mainRow.find('[data-type="keyword"]').val().trim();
        const title = mainRow.find('[data-type="title"]').val().trim();
        const description = mainRow.find('[data-type="description"]').val().trim();
        const slug = mainRow.find('[data-type="slug"]').val().trim();
        
        // Determina il tipo di contenuto
        const isProduct = mainRow.hasClass('woocommerce-product-row');
        const isTaxonomy = mainRow.hasClass('taxonomy-row');
        const originalTitle = mainRow.find('td:first-child strong').text();
        
        // Parse delle variabili SEO
        const finalTitle = parseSeoVars(title) || originalTitle;
        const finalDescription = parseSeoVars(description) || 'Descrizione non disponibile per questo contenuto.';
        
        // 1. Aggiorna la query nella barra di ricerca
        const searchQuery = keyword || finalTitle;
        preview.find('.search-query').text(searchQuery);
        
        // 2. Aggiorna le statistiche dei risultati (randomizzate ma realistiche)
        const randomResults = Math.floor(Math.random() * 5000000) + 100000;
        const randomTime = (Math.random() * 0.8 + 0.1).toFixed(2);
        preview.find('.results-count').text(`Circa ${randomResults.toLocaleString('it-IT')} risultati (${randomTime} secondi)`);
        
        // 3. Aggiorna URL e breadcrumb
        let breadcrumb = '';
        if (isTaxonomy) {
            breadcrumb = ' › Categoria';
        } else if (isProduct) {
            breadcrumb = ' › Prodotto';
        } else {
            breadcrumb = ' › Articolo';
        }
        preview.find('.breadcrumb').text(breadcrumb);
        
        // 4. Aggiorna il titolo del risultato
        preview.find('.result-title a').text(finalTitle);
        
        // 5. Aggiorna la descrizione con evidenziazione keyword
        let highlightedDesc = finalDescription;
        if (keyword && finalDescription.toLowerCase().includes(keyword.toLowerCase())) {
            const keywordRegex = new RegExp(`(${keyword})`, 'gi');
            highlightedDesc = finalDescription.replace(keywordRegex, '<strong style="background: #fff3cd; padding: 0 2px;">$1</strong>');
        }
        preview.find('.result-description').html(highlightedDesc);
        
        // 6. Gestisci contenuti specifici per prodotti WooCommerce
        const productInfo = preview.find('.product-info');
        if (isProduct) {
            productInfo.show();
            // Prezzi randomici realistici per demo
            const randomPrice = (Math.random() * 200 + 10).toFixed(2);
            const randomReviews = Math.floor(Math.random() * 500) + 1;
            const randomStars = Math.floor(Math.random() * 5) + 1;
            
            productInfo.find('.product-price').text(`€ ${randomPrice}`);
            productInfo.find('.reviews').text(`(${randomReviews} recensioni)`);
            
            // Genera stelle
            let stars = '';
            for (let i = 0; i < 5; i++) {
                stars += i < randomStars ? '⭐' : '☆';
            }
            productInfo.find('.stars').text(stars);
        } else {
            productInfo.hide();
        }
        
        // 7. Aggiorna tempo di lettura per articoli
        if (!isProduct && !isTaxonomy) {
            const readingTime = Math.ceil((finalDescription.length || 100) / 200);
            preview.find('.reading-time').text(`⏱️ ${readingTime} min di lettura`);
        }
        
        // 8. Aggiorna data
        preview.find('.result-date').text(`📅 ${mebData.current_date}`);
        
        // NUOVO: 9. Aggiungi indicatore lingua se multilingua è attivo
        if (mebData.multilang && mebData.multilang.enabled) {
            const currentLang = mebData.multilang.current;
            if (currentLang && currentLang !== 'all') {
                const langInfo = mebData.multilang.languages[currentLang];
                if (langInfo && langInfo.flag) {
                    // Aggiungi bandierina all'URL del sito
                    const siteUrlElement = preview.find('.site-url');
                    const currentUrl = siteUrlElement.text();
                    if (!currentUrl.includes('img')) {
                        const flag = `<img src="${langInfo.flag}" style="width:12px;height:9px;margin-right:4px;vertical-align:middle;" />`;
                        siteUrlElement.html(flag + currentUrl);
                    }
                }
            }
        }
        
        console.log('Google Preview aggiornato con supporto multilingua:', finalTitle);
    }

    // ===================================================================
    // 8. ANALISI SEO (INVARIATA)
    // ===================================================================
    function runSeoAnalysis(mainRow) {
        const drawer = mainRow.next('.meb-drawer-row');
        const keyword = mainRow.find('[data-type="keyword"]').val().toLowerCase().trim();
        const title = parseSeoVars(mainRow.find('[data-type="title"]').val()).toLowerCase();
        const desc = parseSeoVars(mainRow.find('[data-type="description"]').val()).toLowerCase();
        const slug = mainRow.find('[data-type="slug"]').val().toLowerCase();
        
        let score = 0, maxScore = 8;
        let results = [];
        
        const addResult = (check, text, type = 'normal') => {
            results.push({ check, text, type });
            if (check) score++;
        };
        
        // Controlli SEO standard
        addResult(title.length > 10 && title.length <= limits.title, 
            `Meta Title: ${title.length} caratteri (ideale: 30-60)`, 'length');
        
        addResult(desc.length > 70 && desc.length <= limits.description, 
            `Meta Description: ${desc.length} caratteri (ideale: 120-160)`, 'length');
        
        addResult(slug.length > 3 && slug.length <= limits.slug, 
            `Slug: ${slug.length} caratteri (ideale: 10-75)`, 'length');
        
        if (!keyword) {
            maxScore -= 3;
            addResult(false, 'Focus Keyword non impostata', 'keyword');
        } else {
            addResult(title.includes(keyword), 'Keyword presente nel Meta Title', 'keyword');
            addResult(desc.includes(keyword), 'Keyword presente nella Meta Description', 'keyword');
            addResult(slug.includes(keyword.replace(/\s+/g, '-')), 'Keyword presente nello Slug', 'keyword');
        }
        
        addResult(powerWords.some(word => title.includes(word)), 'Uso di "Power Words" nel titolo', 'engagement');
        addResult(/^\d/.test(title), 'Il titolo inizia con un numero', 'engagement');
        
        // NUOVO: Controllo specifico per multilingua
        if (mebData.multilang && mebData.multilang.enabled) {
            const currentLang = mebData.multilang.current;
            if (currentLang && currentLang !== 'all') {
                maxScore += 1;
                const langInfo = mebData.multilang.languages[currentLang];
                if (langInfo) {
                    addResult(true, `Lingua target: ${langInfo.name} (${currentLang})`, 'multilang');
                }
            }
        }
        
        // Aggiorna il risultato visivo
        const analysisBar = drawer.find('.analysis-progress-bar');
        const resultList = drawer.find('.seo-analysis-details ul');
        
        let listHtml = '';
        results.forEach(res => {
            let icon = res.check ? '✅' : '❌';
            let typeClass = res.type;
            listHtml += `<li class="analysis-item ${typeClass}"><span class="status-icon">${icon}</span> ${res.text}</li>`;
        });
        resultList.html(listHtml);
        
        const scorePercent = (score / maxScore) * 100;
        analysisBar.css('width', scorePercent + '%').removeClass('is-poor is-ok is-good');
        
        if (scorePercent >= 80) analysisBar.addClass('is-good');
        else if (scorePercent >= 50) analysisBar.addClass('is-ok');
        else analysisBar.addClass('is-poor');
        
        return scorePercent;
    }

    // ===================================================================
    // 9. INDICATORI DI LUNGHEZZA (INVARIATI)
    // ===================================================================
    function updateFieldIndicators(input) {
        const mainRow = input.closest('.meb-main-row');
        const type = input.data('type');
        const limit = limits[type];
        const indicator = input.next('.meb-length-indicator');
        const bar = indicator.find('.indicator-bar');
        const countSpan = indicator.find('.indicator-text');
        
        let textToCount = input.val();
        if (type === 'title' || type === 'description') {
            textToCount = parseSeoVars(textToCount);
        }
        
        const currentLength = textToCount.length;
        const percent = Math.min(100, (currentLength / limit) * 100);
        
        let colorClass = 'ok';
        if (currentLength > limit) {
            colorClass = 'over';
        } else if (currentLength > limit * 0.9) {
            colorClass = 'warning';
        }
        
        countSpan.text(currentLength + ' / ' + limit);
        bar.css('width', percent + '%').removeClass('ok warning over').addClass(colorClass);
        
        // Aggiorna preview se il drawer è aperto
        const drawer = mainRow.next('.meb-drawer-row');
        if (drawer.is(':visible')) {
            updateGooglePreview(mainRow);
            runSeoAnalysis(mainRow);
        }
    }

    // ===================================================================
    // 10. EVENTI E INTERAZIONI (INVARIATI)
    // ===================================================================
    
    // Traccia le modifiche ai campi
    $('.meb-table').on('input', '.meb-meta-input', function() {
        modifiedRows.add($(this).closest('.meb-main-row').data('post-id'));
        updateFieldIndicators($(this));
    });

    // Toggle drawer analisi e preview
    $('.meb-table').on('click', '.meb-analyze-button', function(e) {
        e.preventDefault();
        const mainRow = $(this).closest('.meb-main-row');
        const drawer = mainRow.next('.meb-drawer-row');
        
        if (!drawer.is(':visible')) {
            // Chiudi altri drawer aperti
            $('.meb-drawer-row:visible').slideUp(200);
            
            // Aggiorna preview e analisi
           updateGooglePreview(mainRow);
           runSeoAnalysis(mainRow);
           
           // Apri questo drawer
           drawer.slideDown(300).addClass('is-open');
       } else {
           drawer.slideUp(200).removeClass('is-open');
       }
   });

   // Toggle preview desktop/mobile
   $('.meb-table').on('click', '.meb-preview-toggle', function() {
       const button = $(this);
       const drawer = button.closest('.meb-drawer-row');
       const device = button.data('device');
       
       drawer.find('.meb-preview-toggle').removeClass('active');
       button.addClass('active');
       drawer.find('.google-serp-preview').attr('data-device', device);
       
       // Aggiorna alcune specifiche per mobile
       if (device === 'mobile') {
           drawer.find('.google-serp-preview').addClass('mobile-view');
       } else {
           drawer.find('.google-serp-preview').removeClass('mobile-view');
       }
   });

   // ===================================================================
   // 11. SALVATAGGIO DATI CON SUPPORTO MULTILINGUA (INVARIATO)
   // ===================================================================
   $('#meb-bulk-edit-form').on('submit', function(e) {
       e.preventDefault();
       const form = $(this);
       const submitButton = form.find('button[type="submit"]');
       
       if (submitButton.hasClass('is-busy') || modifiedRows.size === 0) {
           if (modifiedRows.size === 0) {
               alert('Nessuna modifica da salvare.');
           }
           return;
       }
       
       const postsData = [];
       modifiedRows.forEach(postId => {
           const row = form.find(`tr[data-post-id="${postId}"]`);
           if (row.length) {
               const itemType = row.data('type') === 'taxonomy' ? 'taxonomy_' + row.data('taxonomy') : 'post';
               
               // NUOVO: Aggiungi informazioni lingua se disponibili
               let languageInfo = null;
               if (mebData.multilang && mebData.multilang.enabled) {
                   languageInfo = mebData.multilang.current;
               }
               
               const postData = {
                   postId: postId,
                   itemType: itemType,
                   metaTitle: row.find('[data-type="title"]').val(),
                   metaDesc: row.find('[data-type="description"]').val(),
                   postSlug: row.find('[data-type="slug"]').val(),
                   focusKeyword: row.find('[data-type="keyword"]').val()
               };
               
               // Aggiungi lingua se multilingua è attivo
               if (languageInfo) {
                   postData.language = languageInfo;
               }
               
               postsData.push(postData);
           }
       });
       
       submitButton.addClass('is-busy').prop('disabled', true);
       submitButton.find('.save-text').text('Salvataggio...');
       
       // NUOVO: Messaggio di salvataggio con info lingua
       let savingMessage = 'Salvataggio in corso...';
       if (mebData.multilang && mebData.multilang.enabled && mebData.multilang.current !== 'all') {
           const langInfo = mebData.multilang.languages[mebData.multilang.current];
           if (langInfo) {
               const flag = langInfo.flag ? `<img src="${langInfo.flag}" style="width:16px;height:12px;margin-right:4px;" />` : '';
               savingMessage = `${flag}Salvataggio per ${langInfo.name}...`;
           }
       }
       
       // Mostra indicatore di caricamento con info lingua
       const loadingIndicator = $(`
           <div class="meb-saving-indicator" style="position: fixed; top: 32px; right: 20px; background: #0073aa; color: white; padding: 12px 20px; border-radius: 4px; z-index: 9999; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
               <span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; margin-right: 8px;"></span>
               ${savingMessage}
           </div>
       `);
       $('body').append(loadingIndicator);
       
       $.ajax({
           url: mebData.api_url,
           method: 'POST',
           beforeSend: function(xhr) {
               xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
           },
           data: {
               posts: postsData
           },
           timeout: 30000
       }).done(function(response) {
           console.log('MEB: Salvataggio completato, aggiorno grafico');
           
           // Rimuovi indicatore di caricamento
           $('.meb-saving-indicator').remove();
           
           // NUOVO: Messaggio di successo con info lingua
           let successText = `✅ Salvato! ${postsData.length} elementi aggiornati con successo.`;
           if (mebData.multilang && mebData.multilang.enabled && mebData.multilang.current !== 'all') {
               const langInfo = mebData.multilang.languages[mebData.multilang.current];
               if (langInfo) {
                   const flag = langInfo.flag ? `<img src="${langInfo.flag}" style="width:16px;height:12px;margin-right:4px;" />` : '';
                   successText = `${flag}Salvato per ${langInfo.name}! ${postsData.length} elementi aggiornati.`;
               }
           }
           
           const successMessage = $(`
               <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                   <p><strong>${successText}</strong></p>
               </div>
           `);
           $('.meb-wrap h1').after(successMessage);
           
           // Auto-dismiss dopo 4 secondi
           setTimeout(() => {
               successMessage.fadeOut(400, () => successMessage.remove());
           }, 4000);
           
           // Aggiorna il grafico dopo 1 secondo
           setTimeout(function() {
               if (typeof window.updateChart === 'function') {
                   window.updateChart();
               }
           }, 1000);
           
           // Rimuovi le righe dalla vista "Da Ottimizzare" se sono state ottimizzate
           if (window.location.href.includes('to_optimize')) {
               postsData.forEach((post, index) => {
                   const row = form.find(`tr[data-post-id="${post.postId}"]`);
                   const title = row.find('[data-type="title"]').val().trim();
                   const desc = row.find('[data-type="description"]').val().trim();
                   
                   if (title !== '' && desc !== '') {
                       setTimeout(() => {
                           row.add(row.next('.meb-drawer-row')).fadeOut(400, function() {
                               $(this).remove();
                           });
                       }, index * 100);
                   }
               });
           }
           
           modifiedRows.clear();
           
       }).fail(function(xhr, status, error) {
           console.error('MEB: Errore salvataggio:', error);
           
           // Rimuovi indicatore di caricamento
           $('.meb-saving-indicator').remove();
           
           let errorText = '❌ Errore! Impossibile salvare le modifiche. Riprova.';
           
           // NUOVO: Gestione errori specifici per multilingua
           if (xhr.status === 403) {
               errorText = '🔒 Errore di permessi. Verifica di avere i diritti necessari.';
           } else if (xhr.status === 0) {
               errorText = '🌐 Errore di connessione. Controlla la tua connessione internet.';
           } else if (mebData.multilang && mebData.multilang.enabled) {
               const langInfo = mebData.multilang.languages[mebData.multilang.current];
               if (langInfo) {
                   errorText = `❌ Errore durante il salvataggio per ${langInfo.name}. Riprova.`;
               }
           }
           
           const errorMessage = $(`
               <div class="notice notice-error is-dismissible" style="margin: 20px 0;">
                   <p><strong>${errorText}</strong></p>
               </div>
           `);
           $('.meb-wrap h1').after(errorMessage);
           
           setTimeout(() => {
               errorMessage.fadeOut(400, () => errorMessage.remove());
           }, 6000);
           
       }).always(function() {
           submitButton.removeClass('is-busy').prop('disabled', false);
           submitButton.find('.save-text').text('Salva Modifiche');
           $('.meb-saving-indicator').remove();
       });
   });

   // ===================================================================
   // 12. NUOVE FUNZIONI PER GESTIONE IMMAGINI
   // ===================================================================
   
   // Variabili globali per la gestione immagini
   let currentImagePage = 1;
   let currentImageFilter = 'all';
   let currentImageSearch = '';
   let selectedImages = new Set();
   let isLoadingImages = false;
   
   // Inizializza gestione immagini se siamo sulla pagina corretta
   if (window.location.href.includes('meb-seo-images')) {
       initializeImageManagement();
   }
   
   function initializeImageManagement() {
       console.log('MEB: Inizializzazione gestione immagini');
       
       // Carica le immagini iniziali
       loadImages();
       
       // Event listeners per filtri
       $('#meb-apply-filters').on('click', applyImageFilters);
       $('#meb-reset-filters').on('click', resetImageFilters);
       
       // Event listeners per ricerca
       $('#meb-image-search').on('keypress', function(e) {
           if (e.which === 13) { // Enter
               applyImageFilters();
           }
       });
       
       // Event listeners per azioni in blocco
       $('#meb-bulk-optimize-all').on('click', bulkOptimizeAll);
       $('#meb-generate-all-alt').on('click', generateAllAltText);
       $('#meb-bulk-optimize-selected').on('click', bulkOptimizeSelected);
       $('#meb-bulk-generate-alt-selected').on('click', bulkGenerateAltSelected);
       $('#meb-clear-selection').on('click', clearSelection);
       
       // Event listeners per modal
       $(document).on('click', '.meb-modal-close', closeImageModal);
       $(document).on('click', '#meb-save-image-changes', saveImageChanges);
       $(document).on('click', '#meb-optimize-single', optimizeSingleImage);
       $(document).on('click', '#meb-generate-alt-single', generateSingleAlt);
   }
   
   function loadImages(page = 1, filter = 'all', search = '') {
       if (isLoadingImages) return;
       
       isLoadingImages = true;
       currentImagePage = page;
       currentImageFilter = filter;
       currentImageSearch = search;
       
       const container = $('#meb-images-container');
       
       if (page === 1) {
           container.html(`
               <div class="meb-loading-images" style="text-align: center; padding: 40px; grid-column: 1 / -1;">
                   <div class="spinner is-active"></div>
                   <p>${mebData.images.labels.loading || 'Caricamento immagini...'}</p>
               </div>
           `);
       }
       
       $.ajax({
           url: mebData.images.api_url + 'get-images',
           method: 'GET',
           data: {
               page: page,
               per_page: 20,
               filter: filter,
               search: search
           },
           beforeSend: function(xhr) {
               xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
           }
       }).done(function(response) {
           displayImages(response, page === 1);
           updateImagesPagination(response);
           updateImagesInfo(response);
       }).fail(function(xhr, status, error) {
           console.error('MEB: Errore caricamento immagini:', error);
           container.html(`
               <div style="text-align: center; padding: 40px; color: #d63638; grid-column: 1 / -1;">
                   <h4>❌ Errore di Caricamento</h4>
                   <p>Impossibile caricare le immagini. Riprova.</p>
                   <button onclick="location.reload()" class="button button-secondary">Ricarica</button>
               </div>
           `);
       }).always(function() {
           isLoadingImages = false;
       });
   }
   
   function displayImages(response, replace = true) {
       const container = $('#meb-images-container');
       
       if (replace) {
           container.empty();
       }
       
       if (!response.images || response.images.length === 0) {
           container.html(`
               <div style="text-align: center; padding: 40px; color: #666; grid-column: 1 / -1;">
                   <div style="font-size: 48px; margin-bottom: 20px;">🖼️</div>
                   <h3>Nessuna immagine trovata</h3>
                   <p>Prova a modificare i filtri di ricerca</p>
               </div>
           `);
           return;
       }
       
       response.images.forEach(image => {
           const imageCard = createImageCard(image);
           container.append(imageCard);
       });
       
       // Inizializza eventi per le nuove card
       initializeImageCardEvents();
   }
   
   function createImageCard(image) {
       const isSelected = selectedImages.has(image.id);
       const statusClass = getImageStatusClass(image);
       const statusText = getImageStatusText(image);
       
       return $(`
           <div class="meb-image-card ${isSelected ? 'selected' : ''}" data-image-id="${image.id}">
               <input type="checkbox" class="meb-image-checkbox" ${isSelected ? 'checked' : ''}>
               <div class="meb-image-status ${statusClass}">${statusText}</div>
               
               <div class="meb-image-thumbnail" data-image-id="${image.id}">
                   <img src="${image.thumb_url}" alt="${image.alt_text || 'Immagine senza testo alternativo'}" loading="lazy">
               </div>
               
               <div class="meb-image-info">
                   <div class="meb-image-title">${image.title || image.filename}</div>
                   <div class="meb-image-meta">${image.dimensions.width}x${image.dimensions.height} • ${image.file_size_human}</div>
                   
                   <div class="meb-image-alt ${!image.alt_text ? 'empty' : ''}">
                       ${image.alt_text || 'Nessun testo alternativo'}
                   </div>
                   
                   <div class="meb-image-actions">
                       <button class="button button-small meb-edit-image" data-image-id="${image.id}">Modifica</button>
                       <button class="button button-small meb-optimize-image" data-image-id="${image.id}" ${image.is_optimized ? 'disabled' : ''}>
                           ${image.is_optimized ? 'Ottimizzata' : 'Ottimizza'}
                       </button>
                   </div>
               </div>
           </div>
       `);
   }
   
   function getImageStatusClass(image) {
       if (!image.alt_text) return 'no-alt';
       if (image.file_size > 1048576) return 'large';
       if (image.is_optimized) return 'optimized';
       return '';
   }
   
   function getImageStatusText(image) {
       if (!image.alt_text) return 'No ALT';
       if (image.file_size > 1048576) return 'Grande';
       if (image.is_optimized) return 'Ottimizzata';
       return '';
   }
   
   function initializeImageCardEvents() {
       // Selezione immagini
       $('.meb-image-checkbox').off('change').on('change', function() {
           const imageId = parseInt($(this).closest('.meb-image-card').data('image-id'));
           const card = $(this).closest('.meb-image-card');
           
           if ($(this).is(':checked')) {
               selectedImages.add(imageId);
               card.addClass('selected');
           } else {
               selectedImages.delete(imageId);
               card.removeClass('selected');
           }
           
           updateBulkActionsBar();
       });
       
       // Click su thumbnail per aprire modal
       $('.meb-image-thumbnail').off('click').on('click', function() {
           const imageId = $(this).data('image-id');
           openImageModal(imageId);
       });
       
       // Pulsante modifica
       $('.meb-edit-image').off('click').on('click', function() {
           const imageId = $(this).data('image-id');
           openImageModal(imageId);
       });
       
       // Pulsante ottimizza
       $('.meb-optimize-image').off('click').on('click', function() {
           const imageId = $(this).data('image-id');
           optimizeImage(imageId, $(this));
       });
   }
   
   function updateBulkActionsBar() {
       const count = selectedImages.size;
       const bar = $('.meb-bulk-actions-bar');
       
       if (count > 0) {
           bar.show();
           $('#meb-selected-count').text(`${count} immagini selezionate`);
       } else {
           bar.hide();
       }
   }
   
   function applyImageFilters() {
       const filter = $('#meb-image-filter').val();
       const search = $('#meb-image-search').val();
       loadImages(1, filter, search);
   }
   
   function resetImageFilters() {
       $('#meb-image-filter').val('all');
       $('#meb-image-search').val('');
       loadImages(1, 'all', '');
   }
   
   function openImageModal(imageId) {
       const modal = $('#meb-image-modal');
       
       // Trova i dati dell'immagine
       const imageCard = $(`.meb-image-card[data-image-id="${imageId}"]`);
       const imageData = getImageDataFromCard(imageCard, imageId);
       
       if (!imageData) {
           console.error('MEB: Dati immagine non trovati');
           return;
       }
       
       // Popola il modal
       $('#meb-modal-image').attr('src', imageData.url);
       $('#meb-modal-filename').text(imageData.filename);
       $('#meb-modal-dimensions').text(`${imageData.dimensions.width}x${imageData.dimensions.height}`);
       $('#meb-modal-filesize').text(imageData.file_size_human);
       $('#meb-modal-alt-text').val(imageData.alt_text || '');
       
       // Aggiorna stato ottimizzazione
       updateOptimizationStatus(imageData);
       
       // Salva ID immagine corrente
       modal.data('current-image-id', imageId);
       
       // Mostra modal
       modal.show();
   }
   
   function getImageDataFromCard(card, imageId) {
       if (!card.length) return null;
       
       const img = card.find('img');
       const title = card.find('.meb-image-title').text();
       const meta = card.find('.meb-image-meta').text();
       const alt = card.find('.meb-image-alt').text();
       
       const dimensions = meta.match(/(\d+)x(\d+)/);
       const fileSize = meta.match(/•\s*(.+)$/);
       
       return {
           id: imageId,
           title: title,
           filename: title,
           url: img.attr('src').replace('-150x150', ''), // Rimuovi suffisso thumbnail
           thumb_url: img.attr('src'),
           alt_text: alt === 'Nessun testo alternativo' ? '' : alt,
           dimensions: {
               width: dimensions ? parseInt(dimensions[1]) : 0,
               height: dimensions ? parseInt(dimensions[2]) : 0
           },
           file_size_human: fileSize ? fileSize[1] : '',
           is_optimized: card.find('.meb-optimize-image').is(':disabled')
       };
   }
   
   function updateOptimizationStatus(imageData) {
       const statusDiv = $('#meb-optimization-status');
       
       if (imageData.is_optimized) {
           statusDiv.html(`
               <h4>✅ Immagine Ottimizzata</h4>
               <p>Questa immagine è già stata ottimizzata.</p>
               ${imageData.savings ? `<p><strong>Risparmio:</strong> ${imageData.savings_percent}% (${(imageData.savings / 1024).toFixed(1)} KB)</p>` : ''}
           `);
       } else {
           statusDiv.html(`
               <h4>⚠️ Non Ottimizzata</h4>
               <p>Questa immagine può essere ottimizzata per migliorare le performance.</p>
               <p><strong>Dimensione attuale:</strong> ${imageData.file_size_human}</p>
           `);
       }
   }
   
   function closeImageModal() {
       $('#meb-image-modal').hide();
   }
   
   function saveImageChanges() {
       const modal = $('#meb-image-modal');
       const imageId = modal.data('current-image-id');
       const altText = $('#meb-modal-alt-text').val().trim();
       
       if (!imageId) return;
       
       const button = $('#meb-save-image-changes');
       button.prop('disabled', true).text('Salvataggio...');
       
       $.ajax({
           url: mebData.images.api_url + 'generate-alt-text',
           method: 'POST',
           data: {
               attachment_id: imageId,
               alt_text: altText
           },
           beforeSend: function(xhr) {
               xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
           }
       }).done(function(response) {
           if (response.success) {
               // Aggiorna la card dell'immagine
               const imageCard = $(`.meb-image-card[data-image-id="${imageId}"]`);
               imageCard.find('.meb-image-alt').text(altText || 'Nessun testo alternativo')
                   .toggleClass('empty', !altText);
               
               showSuccessMessage('Modifiche salvate con successo!');
               closeImageModal();
               
               // Ricarica immagini se stiamo filtrando per "senza ALT"
               if (currentImageFilter === 'no_alt' && altText) {
                   setTimeout(() => loadImages(currentImagePage, currentImageFilter, currentImageSearch), 500);
               }
           }
       }).fail(function() {
           showErrorMessage('Errore durante il salvataggio');
       }).always(function() {
           button.prop('disabled', false).text('Salva Modifiche');
       });
   }
   
   function generateSingleAlt() {
       const modal = $('#meb-image-modal');
       const imageId = modal.data('current-image-id');
       
       if (!imageId) return;
       
       const button = $('#meb-generate-alt-single');
       button.prop('disabled', true).text('Generazione...');
       
       $.ajax({
           url: mebData.images.api_url + 'generate-alt-text',
           method: 'POST',
           data: {
               attachment_id: imageId
           },
           beforeSend: function(xhr) {
               xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
           }
       }).done(function(response) {
           if (response.success) {
               $('#meb-modal-alt-text').val(response.alt_text);
               showSuccessMessage('Testo alternativo generato!');
           }
       }).fail(function() {
           showErrorMessage('Errore durante la generazione');
       }).always(function() {
           button.prop('disabled', false).text('Genera Automaticamente');
       });
   }
   
   function optimizeSingleImage() {
       const modal = $('#meb-image-modal');
       const imageId = modal.data('current-image-id');
       
       if (!imageId) return;
       
       optimizeImage(imageId, $('#meb-optimize-single'));
   }
   
   function optimizeImage(imageId, button) {
       if (!imageId || !button) return;
       
       const originalText = button.text();
       button.prop('disabled', true).text(mebData.images.labels.optimizing || 'Ottimizzazione...');
       
       $.ajax({
           url: mebData.images.api_url + 'optimize-image',
           method: 'POST',
           data: {
               attachment_id: imageId
           },
           beforeSend: function(xhr) {
               xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
           },
           timeout: 60000 // 60 secondi per l'ottimizzazione
       }).done(function(response) {
           if (response.success) {
               // Aggiorna la card dell'immagine
               const imageCard = $(`.meb-image-card[data-image-id="${imageId}"]`);
               imageCard.find('.meb-optimize-image').text('Ottimizzata').prop('disabled', true);
               imageCard.find('.meb-image-status').removeClass('large no-alt').addClass('optimized').text('Ottimizzata');
               
               // Se c'è alt generato, aggiorna anche quello
               if (response.data && response.data.alt_generated && response.data.alt_text) {
                   imageCard.find('.meb-image-alt').text(response.data.alt_text).removeClass('empty');
               }
               
               showSuccessMessage(`Immagine ottimizzata! Risparmio: ${response.data.savings_percent || 0}%`);
               
               // Aggiorna modal se aperto
               if ($('#meb-image-modal').is(':visible') && $('#meb-image-modal').data('current-image-id') == imageId) {
                   updateOptimizationStatus({
                       is_optimized: true,
                       savings_percent: response.data.savings_percent,
                       savings: response.data.savings
                   });
               }
           }
       }).fail(function(xhr) {
           const errorMsg = xhr.responseJSON && xhr.responseJSON.message ? 
               xhr.responseJSON.message : 'Errore durante ottimizzazione';
           showErrorMessage(errorMsg);
       }).always(function() {
           if (!button.prop('disabled')) {
               button.text(originalText);
           }
       });
   }
   
   function bulkOptimizeAll() {
       if (!confirm('Sei sicuro di voler ottimizzare tutte le immagini? Questa operazione potrebbe richiedere diversi minuti.')) {
           return;
       }
       
       // Ottimizza tutte le immagini non ottimizzate
       const unoptimizedImages = $('.meb-optimize-image:not(:disabled)').map(function() {
           return parseInt($(this).data('image-id'));
       }).get();
       
       if (unoptimizedImages.length === 0) {
           alert('Nessuna immagine da ottimizzare.');
           return;
       }
       
       bulkOptimizeImages(unoptimizedImages);
   }
   
   function bulkOptimizeSelected() {
       if (selectedImages.size === 0) {
           alert(mebData.images.labels.no_images_selected || 'Nessuna immagine selezionata.');
           return;
       }
       
       bulkOptimizeImages(Array.from(selectedImages));
   }
   
   function bulkGenerateAltSelected() {
       if (selectedImages.size === 0) {
           alert(mebData.images.labels.no_images_selected || 'Nessuna immagine selezionata.');
           return;
       }
       
       bulkGenerateAlt(Array.from(selectedImages));
   }
   
   function generateAllAltText() {
       if (!confirm('Genera testo alternativo per tutte le immagini senza ALT?')) {
           return;
       }
       
       // Trova immagini senza ALT
       const imagesWithoutAlt = $('.meb-image-alt.empty').closest('.meb-image-card').map(function() {
           return parseInt($(this).data('image-id'));
       }).get();
       
       if (imagesWithoutAlt.length === 0) {
           alert('Nessuna immagine senza testo alternativo.');
           return;
       }
       
       bulkGenerateAlt(imagesWithoutAlt);
   }
   
   function bulkOptimizeImages(imageIds) {
       if (!imageIds || imageIds.length === 0) return;
       
       const progressModal = createProgressModal('Ottimizzazione in corso...', imageIds.length);
       $('body').append(progressModal);
       
       let processed = 0;
       let successful = 0;
       let failed = 0;
       
       // Processa le immagini in batch di 5
       const batchSize = 5;
       const batches = [];
       
       for (let i = 0; i < imageIds.length; i += batchSize) {
           batches.push(imageIds.slice(i, i + batchSize));
       }
       
       // Funzione per processare un batch
       function processBatch(batchIndex) {
           if (batchIndex >= batches.length) {
               // Completato
               updateProgressModal(progressModal, 100, `Completato: ${successful} successi, ${failed} errori`);
               setTimeout(() => {
                   progressModal.remove();
                   showSuccessMessage(`Ottimizzazione completata! ${successful}/${imageIds.length} immagini ottimizzate.`);
                   
                   // Ricarica le immagini
                   loadImages(currentImagePage, currentImageFilter, currentImageSearch);
               }, 2000);
               return;
           }
           
           const batch = batches[batchIndex];
           
           $.ajax({
               url: mebData.images.bulk_optimize_url,
               method: 'POST',
               data: {
                   attachment_ids: batch
               },
               beforeSend: function(xhr) {
                   xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
               },
               timeout: 120000 // 2 minuti per batch
           }).done(function(response) {
               if (response.success && response.results) {
                   response.results.forEach(result => {
                       processed++;
                       if (result.success) {
                           successful++;
                           // Aggiorna card immagine
                           const imageCard = $(`.meb-image-card[data-image-id="${result.id}"]`);
                           imageCard.find('.meb-optimize-image').text('Ottimizzata').prop('disabled', true);
                           imageCard.find('.meb-image-status').removeClass('large no-alt').addClass('optimized').text('Ottimizzata');
                       } else {
                           failed++;
                       }
                   });
               } else {
                   batch.forEach(() => {
                       processed++;
                       failed++;
                   });
               }
           }).fail(function() {
               batch.forEach(() => {
                   processed++;
                   failed++;
               });
           }).always(function() {
               const progress = Math.round((processed / imageIds.length) * 100);
               updateProgressModal(progressModal, progress, `Processate: ${processed}/${imageIds.length}`);
               
               // Processa il batch successivo
               setTimeout(() => processBatch(batchIndex + 1), 1000);
           });
       }
       
       // Inizia il processing
       processBatch(0);
   }
   
   function bulkGenerateAlt(imageIds) {
       if (!imageIds || imageIds.length === 0) return;
       
       const progressModal = createProgressModal('Generazione testi alternativi...', imageIds.length);
       $('body').append(progressModal);
       
       let processed = 0;
       let successful = 0;
       
       // Processa un'immagine alla volta per evitare overload
       function processNext(index) {
           if (index >= imageIds.length) {
               updateProgressModal(progressModal, 100, 'Completato!');
               setTimeout(() => {
                   progressModal.remove();
                   showSuccessMessage(`Generazione completata! ${successful}/${imageIds.length} testi alternativi generati.`);
                   
                   // Ricarica se stiamo filtrando per "senza ALT"
                   if (currentImageFilter === 'no_alt') {
                       loadImages(currentImagePage, currentImageFilter, currentImageSearch);
                   }
               }, 2000);
               return;
           }
           
           const imageId = imageIds[index];
           
           $.ajax({
               url: mebData.images.api_url + 'generate-alt-text',
               method: 'POST',
               data: {
                   attachment_id: imageId
               },
               beforeSend: function(xhr) {
                   xhr.setRequestHeader('X-WP-Nonce', mebData.api_nonce);
               }
           }).done(function(response) {
               if (response.success && response.alt_text) {
                   successful++;
                   // Aggiorna card immagine
                   const imageCard = $(`.meb-image-card[data-image-id="${imageId}"]`);
                   imageCard.find('.meb-image-alt').text(response.alt_text).removeClass('empty');
                   imageCard.find('.meb-image-status').removeClass('no-alt');
               }
           }).always(function() {
               processed++;
               const progress = Math.round((processed / imageIds.length) * 100);
               updateProgressModal(progressModal, progress, `Processate: ${processed}/${imageIds.length}`);
               
               // Processa la prossima immagine
               setTimeout(() => processNext(index + 1), 500);
           });
       }
       
       processNext(0);
   }
   
   function createProgressModal(title, total) {
       return $(`
           <div class="meb-progress-modal" style="
               position: fixed;
               top: 0;
               left: 0;
               width: 100%;
               height: 100%;
               background: rgba(0,0,0,0.7);
               z-index: 10000;
               display: flex;
               align-items: center;
               justify-content: center;
           ">
               <div style="
                   background: white;
                   padding: 30px;
                   border-radius: 8px;
                   max-width: 400px;
                   width: 90%;
                   text-align: center;
               ">
                   <h3 style="margin-top: 0;">${title}</h3>
                   <div class="meb-progress-bar" style="
                       background: #e0e0e0;
                       height: 20px;
                       border-radius: 10px;
                       overflow: hidden;
                       margin: 20px 0;
                   ">
                       <div class="meb-progress-fill" style="
                           background: linear-gradient(90deg, #4CAF50, #2196F3);
                           height: 100%;
                           width: 0%;
                           transition: width 0.3s ease;
                       "></div>
                   </div>
                   <div class="meb-progress-text">0%</div>
                   <div class="meb-progress-status">Inizializzazione...</div>
                   <p style="margin-bottom: 0; color: #666; font-size: 12px;">
                       Non chiudere questa finestra durante il processo
                   </p>
               </div>
           </div>
       `);
   }
   
   function updateProgressModal(modal, percentage, status) {
       modal.find('.meb-progress-fill').css('width', percentage + '%');
       modal.find('.meb-progress-text').text(percentage + '%');
       modal.find('.meb-progress-status').text(status);
   }
   
   function clearSelection() {
       selectedImages.clear();
       $('.meb-image-card').removeClass('selected');
       $('.meb-image-checkbox').prop('checked', false);
       updateBulkActionsBar();
   }
   
   function updateImagesPagination(response) {
       const pagination = $('#meb-images-pagination');
       
       if (response.pages <= 1) {
           pagination.empty();
           return;
       }
       
       let paginationHtml = '<div class="meb-pagination"><ul>';
       
       // Pulsante precedente
       if (response.current_page > 1) {
           paginationHtml += `<li><a href="#" class="page-numbers" data-page="${response.current_page - 1}">‹</a></li>`;
       }
       
       // Numeri pagina
       const startPage = Math.max(1, response.current_page - 2);
       const endPage = Math.min(response.pages, response.current_page + 2);
       
       for (let i = startPage; i <= endPage; i++) {
           if (i === response.current_page) {
               paginationHtml += `<li><span class="page-numbers current">${i}</span></li>`;
           } else {
               paginationHtml += `<li><a href="#" class="page-numbers" data-page="${i}">${i}</a></li>`;
           }
       }
       
       // Pulsante successivo
       if (response.current_page < response.pages) {
           paginationHtml += `<li><a href="#" class="page-numbers" data-page="${response.current_page + 1}">›</a></li>`;
       }
       
       paginationHtml += '</ul></div>';
       pagination.html(paginationHtml);
       
       // Event listener per paginazione
       pagination.find('.page-numbers').on('click', function(e) {
           e.preventDefault();
           const page = parseInt($(this).data('page'));
           if (page && page !== response.current_page) {
               loadImages(page, currentImageFilter, currentImageSearch);
           }
       });
   }
   
   function updateImagesInfo(response) {
       const info = $('#meb-images-count-info');
       const start = ((response.current_page - 1) * 20) + 1;
       const end = Math.min(response.current_page * 20, response.total);
       
       info.text(`Mostrando ${start}-${end} di ${response.total} immagini`);
   }
   
   function showSuccessMessage(message) {
       showNotification(message, 'success');
   }
   
   function showErrorMessage(message) {
       showNotification(message, 'error');
   }
   
   function showNotification(message, type = 'info') {
       const notification = $(`
           <div class="meb-notification meb-notification-${type}" style="
               position: fixed;
               top: 32px;
               right: 20px;
               background: ${type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#0073aa'};
               color: white;
               padding: 12px 20px;
               border-radius: 4px;
               z-index: 9999;
               box-shadow: 0 2px 8px rgba(0,0,0,0.3);
               max-width: 300px;
               word-wrap: break-word;
           ">
               ${message}
           </div>
       `);
       
       $('body').append(notification);
       
       setTimeout(() => {
           notification.fadeOut(400, () => notification.remove());
       }, 4000);
   }

   // ===================================================================
   // 13. FUNZIONI UTILITY MULTILINGUA (INVARIATE)
   // ===================================================================
   
   // Funzione per ottenere statistiche lingua corrente
   function getCurrentLanguageStats() {
       if (!mebData.multilang || !mebData.multilang.enabled) {
           return null;
       }
       
       const currentLang = mebData.multilang.current;
       const langInfo = mebData.multilang.languages[currentLang];
       
       return {
           code: currentLang,
           info: langInfo,
           isAll: currentLang === 'all'
       };
   }
   
   // Funzione per aggiornare UI in base alla lingua
   function updateUIForLanguage(languageCode) {
       const langStats = getCurrentLanguageStats();
       
       if (!langStats) return;
       
       // Aggiorna titoli e messaggi
       if (langStats.isAll) {
           $('.meb-language-context').text('Modalità Globale - Tutte le Lingue');
       } else if (langStats.info) {
           const flag = langStats.info.flag ? `<img src="${langStats.info.flag}" style="width:16px;height:12px;margin-right:4px;" />` : '';
           $('.meb-language-context').html(`${flag}${langStats.info.name} (${langStats.code})`);
       }
       
       // Aggiorna placeholder e testi dinamici
       $('[data-lang-placeholder]').each(function() {
           const $this = $(this);
           const basePlaceholder = $this.data('lang-placeholder');
           
           if (langStats.isAll) {
               $this.attr('placeholder', basePlaceholder);
           } else if (langStats.info) {
               $this.attr('placeholder', `${basePlaceholder} (${langStats.info.name})`);
           }
       });
   }
   
   // Event listener per cambio lingua
   $(document).on('change', '#meb_language, #meb-chart-language', function() {
       const selectedLang = $(this).val();
       updateUIForLanguage(selectedLang);
       
       // Aggiorna il contesto multilingua
       if (mebData.multilang && mebData.multilang.enabled) {
           mebData.multilang.current = selectedLang;
       }
   });

   // ===================================================================
   // 14. GESTIONE AVANZATA ANTEPRIMA MULTILINGUA (INVARIATA)
   // ===================================================================
   
   // Funzione per personalizzare anteprima Google per lingua specifica
   function customizePreviewForLanguage(preview, languageCode) {
       if (!mebData.multilang || !mebData.multilang.enabled || languageCode === 'all') {
           return;
       }
       
       const langInfo = mebData.multilang.languages[languageCode];
       if (!langInfo) return;
       
       // Personalizza elementi specifici per lingua
       const searchStats = preview.find('.search-stats .results-count');
       const currentText = searchStats.text();
       
       // Aggiungi indicatore di localizzazione
       if (!currentText.includes('🌍')) {
           const localizedText = `🌍 ${currentText} - Risultati per ${langInfo.name}`;
           searchStats.text(localizedText);
       }
       
       // Personalizza URL breadcrumb
       const breadcrumb = preview.find('.breadcrumb');
       const currentBreadcrumb = breadcrumb.text();
       if (!currentBreadcrumb.includes(languageCode)) {
           breadcrumb.text(`${currentBreadcrumb} › ${languageCode.toUpperCase()}`);
       }
       
       // Aggiungi badge lingua al risultato
       const resultTitle = preview.find('.result-title');
       if (!resultTitle.find('.lang-badge').length && langInfo.flag) {
           const langBadge = $(`
               <span class="lang-badge" style="
                   display: inline-block;
                   margin-left: 8px;
                   padding: 2px 6px;
                   background: #f0f0f0;
                   border-radius: 10px;
                   font-size: 11px;
                   color: #666;
                   vertical-align: middle;
               ">
                   <img src="${langInfo.flag}" style="width:12px;height:9px;margin-right:3px;" />${languageCode.toUpperCase()}
               </span>
           `);
           resultTitle.append(langBadge);
       }
   }
   
   // Override della funzione updateGooglePreview per includere personalizzazione lingua
   const originalUpdateGooglePreview = updateGooglePreview;
   updateGooglePreview = function(mainRow) {
       // Chiama la funzione originale
       originalUpdateGooglePreview(mainRow);
       
       // Aggiungi personalizzazioni multilingua
       const drawer = mainRow.next('.meb-drawer-row');
       const preview = drawer.find('.google-serp-preview');
       const currentLang = mebData.multilang ? mebData.multilang.current : 'all';
       
       customizePreviewForLanguage(preview, currentLang);
   };

   // ===================================================================
   // 15. TOOLTIPS E HELP MULTILINGUA (INVARIATI)
   // ===================================================================
   
   function initMultilangTooltips() {
       if (!mebData.multilang || !mebData.multilang.enabled) return;
       
       // Tooltip per il selettore lingua
       $('#meb_language, #meb-chart-language').each(function() {
           const $this = $(this);
           
           $this.on('mouseenter', function() {
               if (!$this.data('tooltip-added')) {
                   $this.attr('title', `Plugin multilingua rilevato: ${mebData.multilang.plugin.toUpperCase()}. Seleziona una lingua per filtrare i contenuti.`);
                   $this.data('tooltip-added', true);
               }
           });
       });
       
       // Tooltip per elementi con indicatori lingua
       $('.meb-stats-container li').on('mouseenter', function() {
           const $this = $(this);
           const labelText = $this.find('strong').text();
           
           if (labelText.includes('(') && labelText.includes(')')) {
               const langMatch = labelText.match(/\(([A-Z]{2,3})\)/);
               if (langMatch) {
                   const langCode = langMatch[1].toLowerCase();
                   const langInfo = mebData.multilang.languages[langCode];
                   
                   if (langInfo && !$this.data('tooltip-added')) {
                       $this.attr('title', `Statistiche per lingua: ${langInfo.name}. Totale disponibile in questa lingua.`);
                       $this.data('tooltip-added', true);
                   }
               }
           }
       });
       
       // NUOVO: Tooltip per immagini
       $(document).on('mouseenter', '.meb-image-status', function() {
           const $this = $(this);
           const statusText = $this.text();
           
           if (!$this.data('tooltip-added')) {
               let tooltipText = '';
               switch (statusText) {
                   case 'No ALT':
                       tooltipText = 'Questa immagine non ha testo alternativo per l\'accessibilità';
                       break;
                   case 'Grande':
                       tooltipText = 'Immagine superiore a 1MB - consigliata ottimizzazione';
                       break;
                   case 'Ottimizzata':
                       tooltipText = 'Immagine già ottimizzata per le performance';
                       break;
               }
               
               if (tooltipText) {
                   $this.attr('title', tooltipText);
                   $this.data('tooltip-added', true);
               }
           }
       });
   }

   // ===================================================================
   // 16. GESTIONE KEYBOARD SHORTCUTS AVANZATI (ESTESI)
   // ===================================================================
   
   // Supporto tasti rapidi con funzionalità multilingua e immagini
   $(document).on('keydown', function(e) {
       // Ctrl+S per salvare
       if (e.ctrlKey && e.which === 83) {
           e.preventDefault();
           if (modifiedRows.size > 0) {
               $('#meb-bulk-edit-form').submit();
           } else if (window.location.href.includes('meb-seo-images') && $('#meb-image-modal').is(':visible')) {
               // Salva modifiche immagine nel modal
               $('#meb-save-image-changes').click();
           }
       }
       
       // Esc per chiudere drawer o modal
       if (e.which === 27) {
           if ($('#meb-image-modal').is(':visible')) {
               closeImageModal();
           } else {
               $('.meb-drawer-row:visible').slideUp(200).removeClass('is-open');
           }
       }
       
       // NUOVO: Ctrl+L per cambio rapido lingua (solo se multilingua attivo)
       if (e.ctrlKey && e.which === 76 && mebData.multilang && mebData.multilang.enabled) {
           e.preventDefault();
           
           const langSelector = $('#meb_language');
           if (langSelector.length) {
               langSelector.focus();
               
               // Mostra un piccolo helper
               const helper = $(`
                   <div class="meb-keyboard-helper" style="
                       position: fixed;
                       top: 50%;
                       left: 50%;
                       transform: translate(-50%, -50%);
                       background: rgba(0,0,0,0.8);
                       color: white;
                       padding: 10px 15px;
                       border-radius: 4px;
                       z-index: 10000;
                       font-size: 12px;
                   ">
                       🌍 Usa le frecce ↑↓ per cambiare lingua
                   </div>
               `);
               
               $('body').append(helper);
               setTimeout(() => helper.fadeOut(300, () => helper.remove()), 2000);
           }
       }
       
       // NUOVO: Ctrl+Shift+G per toggle grafico lingua
       if (e.ctrlKey && e.shiftKey && e.which === 71 && mebData.multilang && mebData.multilang.enabled) {
           e.preventDefault();
           
           const chartLangSelector = $('#meb-chart-language');
           if (chartLangSelector.length) {
               const currentValue = chartLangSelector.val();
               const options = chartLangSelector.find('option');
               let nextIndex = 0;
               
               options.each(function(index) {
                   if ($(this).val() === currentValue) {
                       nextIndex = (index + 1) % options.length;
                       return false;
                   }
               });
               
               const nextValue = options.eq(nextIndex).val();
               chartLangSelector.val(nextValue).trigger('change');
               
               // Feedback visivo
               const feedback = $(`
                   <div class="meb-shortcut-feedback" style="
                       position: fixed;
                       top: 80px;
                       right: 20px;
                       background: #2271b1;
                       color: white;
                       padding: 8px 12px;
                       border-radius: 4px;
                       z-index: 9999;
                       font-size: 12px;
                   ">
                       📊 Grafico: ${options.eq(nextIndex).text()}
                   </div>
               `);
               
               $('body').append(feedback);
               setTimeout(() => feedback.fadeOut(300, () => feedback.remove()), 1500);
           }
       }
       
       // NUOVO: Ctrl+A per selezionare tutte le immagini (pagina immagini)
       if (e.ctrlKey && e.which === 65 && window.location.href.includes('meb-seo-images')) {
           e.preventDefault();
           
           $('.meb-image-checkbox').prop('checked', true).trigger('change');
           showNotification('Tutte le immagini selezionate', 'info');
       }
       
       // NUOVO: Spazio per toggle selezione immagine (se focus su card)
       if (e.which === 32 && $(e.target).closest('.meb-image-card').length) {
           e.preventDefault();
           const card = $(e.target).closest('.meb-image-card');
           const checkbox = card.find('.meb-image-checkbox');
           checkbox.prop('checked', !checkbox.is(':checked')).trigger('change');
       }
   });

   // ===================================================================
   // 17. INIZIALIZZAZIONE FINALE (ESTESA)
   // ===================================================================
   
   // Inizializza indicatori per campi esistenti
   $('.meb-meta-input').each(function() {
       updateFieldIndicators($(this));
   });
   
   // Chiudi tutti i drawer quando si clicca fuori
   $(document).on('click', function(e) {
       if (!$(e.target).closest('.meb-drawer-row, .meb-analyze-button').length) {
           $('.meb-drawer-row:visible').slideUp(200).removeClass('is-open');
       }
   });
   
   // Miglioramenti UX
   $('.meb-meta-input').on('focus', function() {
       $(this).closest('tr').addClass('editing');
   }).on('blur', function() {
       $(this).closest('tr').removeClass('editing');
   });
   
   // Inizializza tooltips multilingua
   initMultilangTooltips();
   
   // Aggiorna UI per lingua corrente
   if (mebData.multilang && mebData.multilang.enabled) {
       updateUIForLanguage(mebData.multilang.current);
   }
   
   // NUOVO: Gestione upload drag & drop per immagini (se sulla pagina immagini)
   if (window.location.href.includes('meb-seo-images')) {
       $(document).on('dragover', function(e) {
           e.preventDefault();
           e.stopPropagation();
           $('body').addClass('meb-drag-over');
       });
       
       $(document).on('dragleave', function(e) {
           e.preventDefault();
           e.stopPropagation();
           $('body').removeClass('meb-drag-over');
       });
       
       $(document).on('drop', function(e) {
           e.preventDefault();
           e.stopPropagation();
           $('body').removeClass('meb-drag-over');
           
           const files = e.originalEvent.dataTransfer.files;
           if (files.length > 0) {
               showNotification('Upload drag & drop non ancora implementato. Usa il media uploader di WordPress.', 'info');
           }
       });
   }
   
   // ===================================================================
   // 18. DEBUG E MONITORING MULTILINGUA (ESTESO)
   // ===================================================================
   
   // Funzione di debug per multilingua
   window.mebDebugMultilang = function() {
       if (!mebData.multilang || !mebData.multilang.enabled) {
           console.log('MEB Debug: Multilingua NON attivo');
           return;
       }
       
       console.group('MEB Debug: Stato Multilingua');
       console.log('Plugin:', mebData.multilang.plugin);
       console.log('Lingua corrente:', mebData.multilang.current);
       console.log('Lingue disponibili:', mebData.multilang.languages);
       console.log('Selettore tabella:', $('#meb_language').val());
       console.log('Selettore grafico:', $('#meb-chart-language').val());
       console.log('Righe modificate:', Array.from(modifiedRows));
       console.groupEnd();
   };
   
   // NUOVO: Funzione di debug per immagini
   window.mebDebugImages = function() {
       if (!mebData.images) {
           console.log('MEB Debug: Modulo immagini NON attivo');
           return;
       }
       
       console.group('MEB Debug: Stato Immagini');
       console.log('Pagina corrente:', currentImagePage);
       console.log('Filtro corrente:', currentImageFilter);
       console.log('Ricerca corrente:', currentImageSearch);
       console.log('Immagini selezionate:', Array.from(selectedImages));
       console.log('Loading in corso:', isLoadingImages);
       console.log('Impostazioni:', mebData.images.settings);
       console.groupEnd();
   };
   
   // Monitoring delle performance
   if (mebData.multilang && mebData.multilang.enabled) {
       console.log('MEB: Modalità multilingua attiva');
       console.log('MEB: Performance monitoring attivo per', Object.keys(mebData.multilang.languages).length, 'lingue');
       
       // Track events per analytics (se necessario)
       $(document).on('change', '#meb_language', function() {
           const selectedLang = $(this).val();
           console.log('MEB Event: Cambio lingua tabella a', selectedLang);
           
           // Qui potresti aggiungere tracking analytics
           // gtag('event', 'language_change', { language: selectedLang });
       });
   }
   
   // NUOVO: Monitoring immagini
   if (mebData.images) {
       console.log('MEB: Modulo ottimizzazione immagini attivo');
       
       // Track eventi immagini
       $(document).on('click', '.meb-optimize-image', function() {
           console.log('MEB Event: Ottimizzazione immagine', $(this).data('image-id'));
       });
       
       $(document).on('change', '.meb-image-checkbox', function() {
           console.log('MEB Event: Selezione immagine', $(this).closest('.meb-image-card').data('image-id'), $(this).is(':checked'));
       });
   }
   
   // CSS per animazione spinner
   $('<style>')
       .prop('type', 'text/css')
       .html(`
           @keyframes spin {
               0% { transform: rotate(0deg); }
               100% { transform: rotate(360deg); }
           }
           .meb-saving-indicator .dashicons-update {
               animation: spin 1s linear infinite;
           }
           .lang-badge {
               transition: all 0.2s ease;
           }
           .lang-badge:hover {
               background: #e0e0e0 !important;
           }
           .meb-drag-over::before {
               content: '';
               position: fixed;
               top: 0;
               left: 0;
               width: 100%;
               height: 100%;
               background: rgba(0, 115, 170, 0.1);
               border: 4px dashed #0073aa;
               z-index: 9998;
               pointer-events: none;
           }
           .meb-notification {
               animation: slideInRight 0.3s ease-out;
           }
           @keyframes slideInRight {
               from { transform: translateX(100%); opacity: 0; }
               to { transform: translateX(0); opacity: 1; }
           }
       `)
       .appendTo('head');
   
   console.log('MEB: Inizializzazione completata con anteprima Google realistica, supporto multilingua completo e gestione immagini avanzata!');
   
   // Esponi funzioni globali per debug
   window.mebUtils = {
       updateChart: window.updateChart,
       loadHistoricalData: window.loadHistoricalData,
       debugMultilang: window.mebDebugMultilang,
       debugImages: window.mebDebugImages,
       getCurrentLanguageStats: getCurrentLanguageStats,
       updateUIForLanguage: updateUIForLanguage,
       loadImages: loadImages,
       optimizeImage: optimizeImage,
       showNotification: showNotification
   };
});
