'use strict';

/**
 * Handgeschriebenes Vanilla-JS, kein Framework und kein Build-Schritt.
 * Die Content-Security-Policy in public/.htaccess erlaubt nur eigene Quellen.
 */
(function () {
    // ---------------------------------------------------------------- Helfer

    /**
     * Muss sich genauso verhalten wie InvoiceController::parsePriceCents(),
     * sonst weicht die Live-Summe vom gespeicherten Wert ab.
     */
    function parsePriceCents(text) {
        var clean = String(text == null ? '' : text).trim().replace(/[^0-9,.\-]/g, '');
        if (clean === '') {
            return 0;
        }

        var lastComma = clean.lastIndexOf(',');
        var lastDot = clean.lastIndexOf('.');

        if (lastComma !== -1 && (lastDot === -1 || lastComma > lastDot)) {
            // Deutsche Schreibweise: Komma trennt die Cent, Punkte sind Tausender.
            clean = clean.replace(/\./g, '').replace(',', '.');
        } else if (lastComma === -1 && /\.\d{3}$/.test(clean)) {
            // "1.000" meint tausend, nicht eins. Genau drei Ziffern hinter dem
            // letzten Punkt gibt es bei Centbetraegen nicht.
            clean = clean.replace(/\./g, '');
        } else {
            clean = clean.replace(/,/g, '');
        }

        var value = parseFloat(clean);

        return isFinite(value) ? Math.round(value * 100) : 0;
    }

    /** Gleiche Darstellung wie Pmz\Support\Format::money(). */
    function formatMoney(cents) {
        var sign = cents < 0 ? '-' : '';
        var abs = Math.abs(cents);
        var whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        var frac = String(abs % 100).padStart(2, '0');

        return sign + whole + ',' + frac + ' €';
    }

    /**
     * Entspricht Format::unitPrice(): zwei bis vier Nachkommastellen.
     * Ein abgeleiteter Tagespreis ist selten glatt, und mit nur zwei Stellen
     * multipliziert er sich nicht mehr zur Zeilensumme zurueck.
     */
    function formatUnitPrice(cents) {
        var text = (cents / 100).toFixed(4).replace('.', ',');
        var parts = text.split(',');
        var frac = parts[1].replace(/0+$/, '');

        return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + frac.padEnd(2, '0');
    }

    function formatPercent(rateBp) {
        var text = (rateBp / 100).toFixed(2).replace('.', ',');

        return text.indexOf(',') === -1 ? text : text.replace(/,?0+$/, '');
    }

    /** Entspricht LineItem::nightsBetween(); UTC, damit keine Sommerzeit stoert. */
    function nightsBetween(from, to) {
        if (!from || !to) {
            return 0;
        }

        var a = Date.parse(from + 'T00:00:00Z');
        var b = Date.parse(to + 'T00:00:00Z');

        if (isNaN(a) || isNaN(b) || b < a) {
            return 0;
        }

        return Math.round((b - a) / 86400000);
    }

    function parseQuantity(text) {
        var value = parseFloat(String(text == null ? '' : text).replace(',', '.'));

        return isFinite(value) && value > 0 ? value : 0;
    }

    // -------------------------------------------------- Rueckfrage bei Formularen

    function initConfirmForms() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        });
    }

    // ------------------------------------------------------------ Kundenauswahl

    /**
     * Stammkunde oder Anschrift als Text. Was nicht gewaehlt ist, wird
     * ausgeblendet - mitgeschickt wird es trotzdem, der Server entscheidet
     * anhand des Modus und nicht anhand stehengebliebener Felder.
     */
    function initCustomerMode() {
        var inputs = document.querySelectorAll('[name="customer_mode"]');

        if (inputs.length === 0) {
            return;
        }

        function apply() {
            var selected = document.querySelector('[name="customer_mode"]:checked');
            var mode = selected === null ? 'stamm' : selected.value;

            document.querySelectorAll('[data-customer-mode]').forEach(function (box) {
                box.hidden = box.dataset.customerMode !== mode;
            });
        }

        inputs.forEach(function (input) {
            input.addEventListener('change', apply);
        });

        apply();
    }

    function initCustomerPicker() {
        var box = document.getElementById('kunde');
        var hidden = document.getElementById('customer_id');
        var search = document.getElementById('kundensuche');
        var results = document.getElementById('kundentreffer');
        var chosen = document.getElementById('kunde-gewaehlt');
        var release = document.getElementById('kunde-loesen');

        if (!box || !hidden || !search || !results) {
            return;
        }

        function fieldsOf() {
            return box.querySelectorAll('[data-customer]');
        }

        function labelFromFields() {
            var parts = [];
            fieldsOf().forEach(function (input) {
                if ((input.dataset.customer === 'company' || input.dataset.customer === 'contact_name') && input.value !== '') {
                    parts.push(input.value);
                }
            });

            return parts.join(', ');
        }

        function applySelection() {
            var selected = hidden.value !== '';

            box.querySelectorAll('[data-hide-when-selected]').forEach(function (el) {
                el.hidden = selected;
            });
            box.querySelectorAll('[data-show-when-selected]').forEach(function (el) {
                el.hidden = !selected;
            });
            // Bei gewaehltem Kunden sind die Felder nur Anzeige: geaendert wird
            // ein Kunde unter /kunden, nicht nebenbei in der Rechnung.
            fieldsOf().forEach(function (input) {
                input.readOnly = selected;
            });

            if (chosen) {
                chosen.textContent = selected ? labelFromFields() : '';
            }
        }

        function fill(customer) {
            fieldsOf().forEach(function (input) {
                var key = input.dataset.customer;
                input.value = customer[key] == null ? '' : String(customer[key]);
            });

            hidden.value = String(customer.id);
            results.hidden = true;
            results.textContent = '';
            search.value = '';
            applySelection();
        }

        function render(list) {
            results.textContent = '';

            if (list.length === 0) {
                results.hidden = true;

                return;
            }

            list.forEach(function (customer) {
                var item = document.createElement('li');
                var button = document.createElement('button');

                button.type = 'button';
                button.className = 'suggestion';
                // textContent statt innerHTML: Kundennamen sind Fremddaten.
                button.textContent = [customer.company, customer.contact_name, customer.postcode + ' ' + customer.city]
                    .filter(function (part) {
                        return String(part).trim() !== '';
                    })
                    .join(' · ');

                button.addEventListener('click', function () {
                    fill(customer);
                });

                item.appendChild(button);
                results.appendChild(item);
            });

            results.hidden = false;
        }

        var timer = null;
        search.addEventListener('input', function () {
            window.clearTimeout(timer);

            var query = search.value.trim();
            if (query.length < 2) {
                results.hidden = true;
                results.textContent = '';

                return;
            }

            timer = window.setTimeout(function () {
                window.fetch('/api/kunden/suche?q=' + encodeURIComponent(query), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                    .then(function (response) {
                        return response.ok ? response.json() : { results: [] };
                    })
                    .then(function (payload) {
                        render(Array.isArray(payload.results) ? payload.results : []);
                    })
                    .catch(function () {
                        results.hidden = true;
                    });
            }, 250);
        });

        if (release) {
            release.addEventListener('click', function () {
                hidden.value = '';
                applySelection();
                search.focus();
            });
        }

        applySelection();
    }

    // --------------------------------------------------------- Rechnungseditor

    function initInvoiceEditor() {
        var source = document.getElementById('editor-data');
        var body = document.getElementById('positions-body');
        var template = document.getElementById('positions-vorlage');
        var empty = document.getElementById('keine-positionen');
        var groupsBody = document.getElementById('summen-gruppen');
        var grossCell = document.getElementById('summe-brutto');

        if (!source || !body || !template) {
            return;
        }

        var data = JSON.parse(source.textContent);
        var basisInputs = document.querySelectorAll('[name="prices_include_tax"]');
        var presets = {};
        data.presets.forEach(function (preset) {
            presets[String(preset.id)] = preset;
        });

        function rows() {
            return Array.prototype.slice.call(body.querySelectorAll('tr.item-row'));
        }

        function field(row, name) {
            return row.querySelector('[data-field="' + name + '"]');
        }

        function output(row, name) {
            return row.querySelector('[data-output="' + name + '"]');
        }

        /** Spiegelt den Umschalter ueber den Positionen. */
        function grossPrices() {
            var checked = document.querySelector('[name="prices_include_tax"]:checked');

            return checked === null ? true : checked.value === '1';
        }

        function rateBpOf(row) {
            var parts = (field(row, 'tax').value || 'S|1900').split('|');

            return parts[0] === 'S' ? (parseInt(parts[1], 10) || 0) : 0;
        }

        function applyKind(row) {
            var kind = field(row, 'kind').value;
            row.classList.toggle('is-accommodation', kind === 'accommodation');
        }

        function applyTax(row) {
            var exempt = field(row, 'tax').value.split('|')[0] === 'E';
            var label = row.querySelector('[data-exemption]');

            if (label) {
                label.hidden = !exempt;
            }
        }

        /** @return {number} Bruttobetrag der Zeile in Cent */
        function recalcRow(row) {
            var accommodation = field(row, 'kind').value === 'accommodation';
            var nights = nightsBetween(field(row, 'date_from').value, field(row, 'date_to').value);
            var quantity;

            field(row, 'nights').value = String(nights);
            output(row, 'nights').textContent = String(nights);

            if (accommodation) {
                var beds = parseInt(field(row, 'beds').value, 10);
                quantity = (isFinite(beds) && beds > 0 ? beds : 0) * nights;
                field(row, 'quantity').value = String(quantity);
            } else {
                quantity = parseQuantity(field(row, 'quantity').value);
            }

            output(row, 'quantity').textContent = String(quantity);

            var unitInput = field(row, 'unit_price_gross');
            var totalInput = field(row, 'line_gross');
            var mode = field(row, 'price_mode');
            var entered;

            if (mode.value === 'total') {
                // Vorgegebener Endpreis, etwa der von Secra inklusive Gebuehren.
                // Der Tagespreis ist dann abgeleitet und selten glatt: 1.250,00
                // auf 44 Einheiten sind 28,4091. Mit zwei Nachkommastellen
                // multipliziert sich das nicht zurueck, deshalb bis zu vier.
                entered = parsePriceCents(totalInput.value);
                unitInput.value = quantity > 0 ? formatUnitPrice(entered / quantity) : '';
            } else {
                entered = Math.round(parsePriceCents(unitInput.value) * quantity);
                totalInput.value = formatMoney(entered).replace(' €', '');
            }

            // Beide Felder bleiben in der eingegebenen Basis stehen. Nach oben
            // geht der Bruttobetrag - genau wie in LineItem::grossCents().
            return grossPrices()
                ? entered
                : Math.round(entered * (10000 + rateBpOf(row)) / 10000);
        }

        /**
         * Spiegelt Pmz\Domain\Calculator::compute(): gerechnet wird in ganzen
         * Cent und gerundet wird je Steuergruppe, nicht je Zeile.
         */
        function updateTotals() {
            var buckets = {};
            var order = [];
            var gross = grossPrices();

            document.querySelectorAll('[data-price-head]').forEach(function (th) {
                th.textContent = gross ? 'Preis brutto' : 'Preis netto';
            });
            document.querySelectorAll('[data-sum-head]').forEach(function (th) {
                th.textContent = gross ? 'Summe brutto' : 'Summe netto';
            });

            rows().forEach(function (row) {
                var gross = recalcRow(row);
                var parts = (field(row, 'tax').value || 'S|1900').split('|');
                var category = parts[0];
                var rateBp = category === 'S' ? (parseInt(parts[1], 10) || 0) : 0;
                var key = category + ':' + rateBp;

                if (!buckets[key]) {
                    buckets[key] = { category: category, rateBp: rateBp, gross: 0 };
                    order.push(key);
                }

                buckets[key].gross += gross;
            });

            var groups = order.map(function (key) {
                return buckets[key];
            });

            groups.sort(function (a, b) {
                var aStandard = a.category === 'S' ? 0 : 1;
                var bStandard = b.category === 'S' ? 0 : 1;

                return aStandard - bStandard || a.rateBp - b.rateBp || a.category.localeCompare(b.category);
            });

            var grossTotal = 0;
            groupsBody.textContent = '';

            groups.forEach(function (group) {
                var net = group.rateBp === 0
                    ? group.gross
                    : Math.round(group.gross * 10000 / (10000 + group.rateBp));
                var tax = group.gross - net;

                grossTotal += group.gross;

                groupsBody.appendChild(totalRow(
                    group.category === 'S' ? 'Netto ' + formatPercent(group.rateBp) + ' %' : 'Netto steuerfrei',
                    net
                ));

                if (tax !== 0) {
                    groupsBody.appendChild(totalRow(
                        (gross ? 'davon ' : 'zzgl. ') + formatPercent(group.rateBp) + ' % USt',
                        tax
                    ));
                }
            });

            grossCell.textContent = formatMoney(grossTotal);
        }

        function totalRow(label, cents) {
            var tr = document.createElement('tr');
            var th = document.createElement('th');
            var td = document.createElement('td');

            th.textContent = label;
            td.className = 'num';
            td.textContent = formatMoney(cents);

            tr.appendChild(th);
            tr.appendChild(td);

            return tr;
        }

        function reindex() {
            var list = rows();

            list.forEach(function (row, index) {
                row.querySelectorAll('[data-field]').forEach(function (input) {
                    input.name = 'items[' + index + '][' + input.dataset.field + ']';
                });

                row.querySelector('[data-move="up"]').disabled = index === 0;
                row.querySelector('[data-move="down"]').disabled = index === list.length - 1;
            });

            if (empty) {
                empty.hidden = list.length > 0;
            }
        }

        function refresh() {
            reindex();
            updateTotals();
        }

        function addRow(values) {
            var row = template.content.firstElementChild.cloneNode(true);

            field(row, 'kind').value = values.kind || 'custom';
            field(row, 'description').value = values.description || '';
            field(row, 'apartment_id').value = values.apartment_id == null ? '' : String(values.apartment_id);
            field(row, 'apartment_name').value = values.apartment_name || '';
            field(row, 'date_from').value = values.date_from || '';
            field(row, 'date_to').value = values.date_to || '';
            field(row, 'beds').value = values.beds == null ? '' : String(values.beds);
            field(row, 'quantity').value = values.quantity == null ? '1' : String(values.quantity);
            field(row, 'tax').value = values.tax || 'S|1900';
            field(row, 'tax_exemption_reason').value = values.tax_exemption_reason || '';
            field(row, 'unit_price_gross').value = values.price_cents == null
                ? ''
                : formatUnitPrice(values.price_cents);
            field(row, 'price_mode').value = values.price_mode === 'total' ? 'total' : 'unit';
            field(row, 'line_gross').value = values.line_gross_cents == null
                ? ''
                : formatMoney(values.line_gross_cents).replace(' €', '');

            body.appendChild(row);
            applyKind(row);
            applyTax(row);
            refresh();

            return row;
        }

        function addFromPreset(preset) {
            // Der Leistungszeitraum steht oben im Formular. Ihn bei jeder
            // Uebernachtungszeile erneut einzutippen ist Doppelarbeit - also
            // vorbelegen; aendern laesst er sich in der Zeile weiterhin.
            var serviceFrom = document.querySelector('[name="service_from"]');
            var serviceTo = document.querySelector('[name="service_to"]');
            var isStay = preset.kind === 'accommodation';
            var rateBp = preset.tax_category === 'S' ? preset.tax_rate_bp : 0;
            var presetPrice = preset.price_is_manual ? null : preset.default_price_cents;

            // Bausteine sind brutto hinterlegt. Im Netto-Modus wird daraus der
            // Nettopreis, damit derselbe Endbetrag herauskommt wie sonst.
            if (presetPrice !== null && !grossPrices()) {
                presetPrice = Math.round(presetPrice * 10000 / (10000 + rateBp));
            }

            var row = addRow({
                kind: preset.kind,
                // Der Platzhalter wird erst beim Waehlen der Wohnung ersetzt.
                description: preset.description_template.indexOf('{apartment}') === -1
                    ? preset.description_template
                    : '',
                date_from: isStay && serviceFrom ? serviceFrom.value : '',
                date_to: isStay && serviceTo ? serviceTo.value : '',
                quantity: 1,
                tax: preset.tax_category + '|' + preset.tax_rate_bp,
                tax_exemption_reason: preset.tax_exemption_reason,
                // Der Nachtpreis wird je Buchung verhandelt und deshalb nie vorbelegt.
                price_cents: presetPrice
            });

            row.dataset.template = preset.description_template;

            var focusTarget = preset.kind === 'accommodation'
                ? field(row, 'apartment_id')
                : field(row, 'description');
            focusTarget.focus();

            return row;
        }

        function applyApartment(row) {
            var select = field(row, 'apartment_id');
            var option = select.options[select.selectedIndex];
            var name = select.value === '' ? '' : option.textContent.trim();

            field(row, 'apartment_name').value = name;

            if (name !== '') {
                field(row, 'beds').value = option.dataset.beds || '1';

                var tpl = row.dataset.template || 'Wohnung {apartment}';
                field(row, 'description').value = tpl.replace('{apartment}', name);
            }
        }

        function move(row, offset) {
            if (offset < 0 && row.previousElementSibling) {
                body.insertBefore(row, row.previousElementSibling);
            } else if (offset > 0 && row.nextElementSibling) {
                body.insertBefore(row.nextElementSibling, row);
            }

            refresh();
        }

        // Bausteine: ein Klick, eine Zeile.
        document.querySelectorAll('[data-preset]').forEach(function (button) {
            button.addEventListener('click', function () {
                var preset = presets[button.dataset.preset];
                if (preset) {
                    addFromPreset(preset);
                }
            });
        });

        body.addEventListener('input', function (event) {
            var row = event.target.closest('tr.item-row');
            if (!row) {
                return;
            }

            // Massgeblich ist das Feld, in dem zuletzt getippt wurde. So lassen
            // sich beide Richtungen bedienen, ohne vorher einen Modus zu waehlen.
            var edited = event.target.dataset.field;
            if (edited === 'line_gross') {
                field(row, 'price_mode').value = 'total';
            } else if (edited === 'unit_price_gross') {
                field(row, 'price_mode').value = 'unit';
            }

            updateTotals();
        });

        body.addEventListener('change', function (event) {
            var row = event.target.closest('tr.item-row');
            if (!row) {
                return;
            }

            if (event.target.dataset.field === 'apartment_id') {
                applyApartment(row);
            }
            if (event.target.dataset.field === 'tax') {
                applyTax(row);
            }

            updateTotals();
        });

        body.addEventListener('click', function (event) {
            var row = event.target.closest('tr.item-row');
            if (!row) {
                return;
            }

            if (event.target.hasAttribute('data-remove')) {
                row.remove();
                refresh();
            } else if (event.target.dataset.move === 'up') {
                move(row, -1);
            } else if (event.target.dataset.move === 'down') {
                move(row, 1);
            }
        });

        // Sortieren ohne Maus: Alt plus Pfeiltaste, solange der Fokus in der Zeile steht.
        body.addEventListener('keydown', function (event) {
            if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
                return;
            }

            var row = event.target.closest('tr.item-row');
            if (!row) {
                return;
            }

            event.preventDefault();
            move(row, event.key === 'ArrowUp' ? -1 : 1);
            event.target.focus();
        });

        var dragged = null;

        body.addEventListener('dragstart', function (event) {
            var handle = event.target.closest('.drag-handle');
            if (!handle) {
                return;
            }

            dragged = handle.closest('tr.item-row');
            dragged.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', '');
        });

        body.addEventListener('dragover', function (event) {
            if (dragged === null) {
                return;
            }

            event.preventDefault();

            var target = event.target.closest('tr.item-row');
            if (!target || target === dragged) {
                return;
            }

            var box = target.getBoundingClientRect();
            var below = (event.clientY - box.top) > box.height / 2;

            body.insertBefore(dragged, below ? target.nextElementSibling : target);
        });

        body.addEventListener('dragend', function () {
            if (dragged === null) {
                return;
            }

            dragged.classList.remove('is-dragging');
            dragged = null;
            refresh();
        });

        // Umschalten laesst die eingetippten Zahlen stehen und deutet sie neu:
        // aus "280 brutto" wird "280 netto". Alles andere waere ein stiller
        // Eingriff in Betraege, die jemand von Hand gesetzt hat.
        basisInputs.forEach(function (input) {
            input.addEventListener('change', updateTotals);
        });

        data.items.forEach(addRow);
        refresh();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmForms();
        initCustomerMode();
        initCustomerPicker();
        initInvoiceEditor();
    });
}());
