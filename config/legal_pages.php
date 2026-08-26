<?php

return [
    'operator_settings' => [
        'legal_operator_name' => '',
        'legal_operator_address' => '',
        'legal_representative' => '',
        'legal_email' => 'support@lookdo.app',
        'legal_phone' => '',
        'legal_register' => '',
        'legal_vat_id' => '',
    ],
    'pages' => [
        'impressum' => [
            'title' => ['de' => 'Impressum', 'en' => 'Legal notice', 'ru' => 'Реквизиты', 'uk' => 'Вихідні дані'],
            'content' => [
                'de' => <<<'HTML'
<h2>Angaben gemäß § 5 DDG</h2>
<address><strong>{{operator_name}}</strong><br>{{operator_address}}</address>
{{#representative}}<h2>Vertretungsberechtigte Person</h2><p>{{representative}}</p>{{/representative}}
<h2>Kontakt</h2>
<p>E-Mail: <a href="mailto:{{email}}">{{email}}</a><br>Telefon: {{phone}}</p>
{{#register}}<h2>Registereintrag</h2><p>{{register}}</p>{{/register}}
{{#vat_id}}<h2>Umsatzsteuer-ID</h2><p>{{vat_id}}</p>{{/vat_id}}
HTML,
                'en' => <<<'HTML'
<h2>Provider information</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address>{{#representative}}<h2>Authorized representative</h2><p>{{representative}}</p>{{/representative}}
<h2>Contact</h2><p>Email: <a href="mailto:{{email}}">{{email}}</a><br>Phone: {{phone}}</p>
{{#register}}<h2>Register entry</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>VAT ID</h2><p>{{vat_id}}</p>{{/vat_id}}

HTML,
                'ru' => <<<'HTML'
<h2>Сведения о владельце сервиса</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address>{{#representative}}<h2>Уполномоченный представитель</h2><p>{{representative}}</p>{{/representative}}
<h2>Контакты</h2><p>Электронная почта: <a href="mailto:{{email}}">{{email}}</a><br>Телефон: {{phone}}</p>
{{#register}}<h2>Запись в реестре</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>Идентификатор плательщика НДС</h2><p>{{vat_id}}</p>{{/vat_id}}

HTML,
                'uk' => <<<'HTML'
<h2>Відомості про власника сервісу</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address>{{#representative}}<h2>Уповноважений представник</h2><p>{{representative}}</p>{{/representative}}
<h2>Контакти</h2><p>Електронна пошта: <a href="mailto:{{email}}">{{email}}</a><br>Телефон: {{phone}}</p>
{{#register}}<h2>Запис у реєстрі</h2><p>{{register}}</p>{{/register}}{{#vat_id}}<h2>Ідентифікатор платника ПДВ</h2><p>{{vat_id}}</p>{{/vat_id}}

HTML,
            ],
        ],
        'datenschutz' => [
            'title' => ['de' => 'Datenschutzerklärung', 'en' => 'Privacy policy', 'ru' => 'Политика конфиденциальности', 'uk' => 'Політика конфіденційності'],
            'content' => [
                'de' => <<<'HTML'
<h2>1. Verantwortlicher</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address><p>E-Mail: <a href="mailto:{{email}}">{{email}}</a><br>Telefon: {{phone}}</p>
<h2>2. Welche Daten wir verarbeiten</h2><p>Je nach Nutzung verarbeiten wir insbesondere technische Zugriffsdaten, IP-Adresse, Zeitpunkt, aufgerufene Adresse und Browserdaten, Konto- und Kontaktdaten, Betriebs- und Vertragsdaten, Zahlungsstatus, Domain- und DNS-Daten sowie Inhalte, Fotos und Videos, die über aktivierte LOOKDO-Funktionen bereitgestellt werden.</p>
<h2>3. Zwecke und Rechtsgrundlagen</h2><ul><li>Bereitstellung, Sicherheit und Fehleranalyse der Plattform auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO.</li><li>Registrierung, Vertragsdurchführung, Abrechnung und Support auf Grundlage von Art. 6 Abs. 1 lit. b DSGVO.</li><li>Erfüllung gesetzlicher Aufbewahrungs- und Nachweispflichten auf Grundlage von Art. 6 Abs. 1 lit. c DSGVO.</li><li>Optionale Verarbeitungen auf Grundlage einer Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO, soweit eine solche abgefragt wird.</li></ul>
<h2>4. Hosting, Sitzungen und lokale Speicherung</h2><p>Für den sicheren Betrieb werden technisch notwendige Sitzungs-Cookies verwendet. Spracheinstellungen können lokal im Browser gespeichert werden. Unser Hosting-Anbieter verarbeitet Serverdaten in unserem Auftrag. Protokolldaten werden nur so lange gespeichert, wie dies für Sicherheit, Fehleranalyse und gesetzliche Pflichten erforderlich ist.</p>
<h2>5. Stripe</h2><p>Wenn ein kostenpflichtiger Tarif gebucht wird, verarbeitet Stripe die zur Zahlungsabwicklung erforderlichen Daten. LOOKDO erhält insbesondere Kunden-, Abonnement- und Zahlungsstatus, jedoch keine vollständigen Kartendaten. Es gelten zusätzlich die Datenschutzinformationen von Stripe.</p>
<h2>6. OpenAI und KI-gestützte Zuordnung</h2><p>LOOKDO versucht Tätigkeitsbeschreibungen zunächst anhand eigener Kategorien und Begriffe zuzuordnen. Nur wenn dies nicht ausreichend ist und die Funktion aktiviert ist, kann der eingegebene Beschreibungstext zur Auswahl aus bestehenden Vorlagen an OpenAI übermittelt werden. Bitte geben Sie dort keine unnötigen personenbezogenen oder vertraulichen Daten ein. Eine automatisierte Zuordnung blockiert die Registrierung nicht und kann vom Nutzer bestätigt oder später geändert werden.</p>
<h2>7. Daten von Kunden eines Betriebs</h2><p>Für Inhalte und Kundendaten innerhalb einer App ist der jeweilige Betrieb regelmäßig Verantwortlicher; LOOKDO verarbeitet diese Daten als technischer Dienstleister nach dessen Weisungen. Der Betrieb muss seine Endkunden über seine eigene Datenverarbeitung informieren.</p>
<h2>8. Empfänger und Drittlandübermittlungen</h2><p>Daten erhalten nur Stellen, die sie für Hosting, Zahlungsabwicklung, Support oder aktivierte KI-Funktionen benötigen. Soweit Anbieter Daten außerhalb des Europäischen Wirtschaftsraums verarbeiten, erfolgt dies nur unter den anwendbaren Garantien der Art. 44 ff. DSGVO.</p>
<h2>9. Speicherdauer</h2><p>Wir löschen Daten, wenn der jeweilige Zweck entfällt und keine gesetzlichen Aufbewahrungs-, Nachweis- oder Sicherungsinteressen entgegenstehen. Vertrags- und Abrechnungsdaten können entsprechend gesetzlicher Fristen länger gespeichert werden. Betriebsinhalte werden nach Vertragsende im Rahmen des vereinbarten Lösch- und Sicherungsverfahrens entfernt.</p>
<h2>10. Ihre Rechte</h2><p>Sie haben nach Maßgabe der DSGVO Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch. Einwilligungen können für die Zukunft widerrufen werden. Außerdem besteht ein Beschwerderecht bei einer Datenschutzaufsichtsbehörde.</p>
<h2>11. Sicherheit und Änderungen</h2><p>Wir verwenden angemessene technische und organisatorische Maßnahmen. Diese Erklärung wird angepasst, wenn Funktionen, Anbieter oder Rechtslage sich ändern. Maßgeblich ist die auf dieser Seite veröffentlichte Fassung.</p>
HTML,
                'en' => <<<'HTML'
<h2>1. Controller</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address><p>Email: <a href="mailto:{{email}}">{{email}}</a><br>Phone: {{phone}}</p>
<h2>2. Data we process</h2><p>Depending on use, we process technical access and browser data, IP address and timestamps, account and contact details, business and contract data, payment status, domain and DNS information, and content, photos or videos provided through enabled LOOKDO functions.</p>
<h2>3. Purposes and legal bases</h2><ul><li>Platform operation, security and troubleshooting: Art. 6(1)(f) GDPR.</li><li>Registration, contract performance, billing and support: Art. 6(1)(b) GDPR.</li><li>Legal retention and evidence obligations: Art. 6(1)(c) GDPR.</li><li>Optional processing based on consent: Art. 6(1)(a) GDPR where requested.</li></ul>
<h2>4. Hosting and sessions</h2><p>Technically necessary session cookies are used. Language preferences may be stored locally in the browser. Hosting providers process server data on our behalf. Logs are retained only as required for security, troubleshooting and legal obligations.</p>
<h2>5. Stripe</h2><p>Stripe processes payment data for paid plans. LOOKDO receives customer, subscription and payment status data, but not complete card details. Stripe's own privacy information also applies.</p>
<h2>6. OpenAI and assisted classification</h2><p>LOOKDO first matches business descriptions against its own catalogue. If this is insufficient and the function is enabled, the description may be sent to OpenAI solely to choose from existing templates. Do not enter unnecessary personal or confidential data. Classification never blocks registration and can be confirmed or changed.</p>
<h2>7. Tenant customer data</h2><p>The relevant business is generally the controller for customer data inside its app; LOOKDO acts as a technical processor following that business's instructions. Each business must provide its own customer privacy information.</p>
<h2>8. Recipients and international transfers</h2><p>Data is shared only with providers required for hosting, payments, support or enabled AI functions. Transfers outside the EEA take place only under the safeguards required by Articles 44 et seq. GDPR.</p>
<h2>9. Retention and rights</h2><p>Data is deleted when no longer needed unless legal retention, evidence or security requirements apply. You may request access, correction, erasure, restriction, portability or object to processing, and may complain to a supervisory authority.</p>
<h2>10. Security and changes</h2><p>We use appropriate technical and organisational measures. This policy may be updated when functions, providers or law change.</p>
HTML,
                'ru' => <<<'HTML'
<h2>1. Ответственный за обработку</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address><p>Электронная почта: <a href="mailto:{{email}}">{{email}}</a><br>Телефон: {{phone}}</p>
<h2>2. Какие данные обрабатываются</h2><p>В зависимости от использования это технические данные доступа и браузера, IP-адрес и время, данные аккаунта и контакты, сведения о бизнесе и договоре, статус оплаты, данные домена и DNS, а также загруженные через функции LOOKDO тексты, фотографии и видео.</p>
<h2>3. Цели и правовые основания</h2><ul><li>Работа, безопасность и диагностика платформы — ст. 6(1)(f) GDPR.</li><li>Регистрация, исполнение договора, расчёты и поддержка — ст. 6(1)(b) GDPR.</li><li>Обязательное хранение и подтверждение операций — ст. 6(1)(c) GDPR.</li><li>Необязательные функции на основании согласия — ст. 6(1)(a) GDPR, если согласие запрашивается.</li></ul>
<h2>4. Хостинг и сессии</h2><p>Используются необходимые сессионные cookie, а язык может сохраняться локально в браузере. Хостинг-провайдер обрабатывает серверные данные по нашему поручению. Журналы хранятся только столько, сколько требуется для безопасности, диагностики и соблюдения закона.</p>
<h2>5. Stripe</h2><p>При выборе платного тарифа Stripe обрабатывает платёжные данные. LOOKDO получает идентификаторы клиента и подписки и статус платежа, но не полные данные банковской карты.</p>
<h2>6. OpenAI и подбор шаблона</h2><p>Сначала LOOKDO использует собственный справочник. Если этого недостаточно и функция включена, описание деятельности может передаваться OpenAI только для выбора из уже существующих шаблонов. Не вводите лишние персональные или конфиденциальные сведения. Подбор не блокирует регистрацию и может быть подтверждён или изменён.</p>
<h2>7. Данные клиентов бизнеса</h2><p>За данные конечных клиентов в приложении обычно отвечает соответствующий бизнес, а LOOKDO обрабатывает их как технический исполнитель по его поручению. Бизнес обязан предоставить клиентам собственную информацию о конфиденциальности.</p>
<h2>8. Получатели и передача за пределы ЕЭЗ</h2><p>Данные получают только поставщики, необходимые для хостинга, платежей, поддержки или включённых функций ИИ. Передача за пределы ЕЭЗ выполняется только с гарантиями, предусмотренными ст. 44 и далее GDPR.</p>
<h2>9. Срок хранения и ваши права</h2><p>Данные удаляются после исчезновения цели обработки, если не действуют обязанности хранения и обеспечения безопасности. Вы вправе запросить доступ, исправление, удаление, ограничение, перенос данных или возразить против обработки, а также обратиться в надзорный орган.</p>
<h2>10. Безопасность и изменения</h2><p>Применяются надлежащие технические и организационные меры. Политика обновляется при изменении функций, поставщиков или законодательства.</p>
HTML,
                'uk' => <<<'HTML'
<h2>1. Відповідальний за обробку</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}</address><p>Електронна пошта: <a href="mailto:{{email}}">{{email}}</a><br>Телефон: {{phone}}</p>
<h2>2. Які дані обробляються</h2><p>Залежно від використання це технічні дані доступу й браузера, IP-адреса та час, дані облікового запису й контакти, відомості про бізнес і договір, статус оплати, дані домену й DNS, а також тексти, фотографії та відео, завантажені через функції LOOKDO.</p>
<h2>3. Цілі та правові підстави</h2><ul><li>Робота, безпека й діагностика платформи — ст. 6(1)(f) GDPR.</li><li>Реєстрація, виконання договору, розрахунки й підтримка — ст. 6(1)(b) GDPR.</li><li>Обов'язкове зберігання та підтвердження операцій — ст. 6(1)(c) GDPR.</li><li>Необов'язкові функції на підставі згоди — ст. 6(1)(a) GDPR, якщо згода запитується.</li></ul>
<h2>4. Хостинг і сесії</h2><p>Використовуються необхідні сесійні cookie, а мова може зберігатися локально в браузері. Хостинг-провайдер обробляє серверні дані за нашим дорученням. Журнали зберігаються лише стільки, скільки потрібно для безпеки, діагностики й вимог закону.</p>
<h2>5. Stripe</h2><p>Для платних тарифів Stripe обробляє платіжні дані. LOOKDO отримує ідентифікатори клієнта й підписки та статус платежу, але не повні дані картки.</p>
<h2>6. OpenAI та добір шаблону</h2><p>Спочатку LOOKDO використовує власний довідник. Якщо цього недостатньо й функцію ввімкнено, опис діяльності може передаватися OpenAI лише для вибору з наявних шаблонів. Не вводьте зайві персональні або конфіденційні відомості. Добір не блокує реєстрацію та може бути підтверджений або змінений.</p>
<h2>7. Дані клієнтів бізнесу</h2><p>За дані кінцевих клієнтів у застосунку зазвичай відповідає відповідний бізнес, а LOOKDO обробляє їх як технічний виконавець за його дорученням. Бізнес повинен надати клієнтам власну інформацію про конфіденційність.</p>
<h2>8. Одержувачі, строки й права</h2><p>Дані отримують лише постачальники, потрібні для хостингу, платежів, підтримки або ввімкнених функцій ШІ. Дані видаляються після зникнення мети обробки, якщо немає законних обов'язків зберігання. Ви можете вимагати доступ, виправлення, видалення, обмеження, перенесення або заперечити проти обробки й звернутися до наглядового органу.</p>
<h2>9. Безпека та зміни</h2><p>Застосовуються належні технічні й організаційні заходи. Політика оновлюється зі зміною функцій, постачальників або законодавства.</p>
HTML,
            ],
        ],
        'agb' => [
            'title' => ['de' => 'Allgemeine Geschäftsbedingungen', 'en' => 'Terms and conditions', 'ru' => 'Условия использования', 'uk' => 'Умови використання'],
            'content' => [
                'de' => <<<'HTML'
<p><strong>Stand: 26. August 2026</strong></p>
<h2>1. Geltungsbereich und Anbieter</h2><p>Diese Bedingungen gelten für Verträge über die SaaS-Plattform LOOKDO zwischen {{operator_name}}, {{operator_address}}, und dem registrierten Betrieb. LOOKDO richtet sich an Unternehmer im Sinne des § 14 BGB. Entgegenstehende Bedingungen des Kunden gelten nur, wenn wir ihnen ausdrücklich zustimmen.</p>
<h2>2. Vertragsschluss und Konto</h2><p>Die Darstellung von Tarifen ist eine Einladung zur Abgabe eines Angebots. Der Vertrag entsteht mit Bestätigung der Registrierung beziehungsweise Freischaltung des gewählten Tarifs. Angaben müssen vollständig und richtig sein. Zugangsdaten sind vertraulich zu behandeln.</p>
<h2>3. Leistungsumfang</h2><p>Der Umfang ergibt sich aus dem gewählten Tarif und den jeweils aktivierten Funktionen. LOOKDO stellt insbesondere Plattformadresse, Verwaltung, Vorlagen, Domain-Anbindung und – je nach Tarif – Anfragen, Medien, Nachrichten, Terminbuchung, KI- oder Integrationsfunktionen bereit. Eine ununterbrochene Verfügbarkeit wird nicht geschuldet; Wartung, Sicherheit und technische Störungen können die Nutzung zeitweise einschränken.</p>
<h2>4. Pflichten des Betriebs</h2><p>Der Betrieb ist für seine Inhalte, Angebote, Endkundenkommunikation und rechtlichen Pflichtinformationen verantwortlich. Unzulässig sind rechtswidrige, irreführende, schädliche oder rechteverletzende Inhalte. Der Betrieb muss erforderliche Rechte an hochgeladenen Texten, Bildern und Videos besitzen und angemessene Sicherungs- und Datenschutzmaßnahmen einhalten.</p>
<h2>5. Preise, Abrechnung und Zahlungsverzug</h2><p>Es gelten die beim Abschluss angezeigten Preise und Abrechnungsintervalle. Zahlungen können über Stripe abgewickelt werden. Bei Zahlungsverzug oder fehlgeschlagener Zahlung dürfen kostenpflichtige Funktionen nach angemessener Ankündigung eingeschränkt werden. Gesetzliche Ansprüche bleiben unberührt.</p>
<h2>6. Domains und externe Dienste</h2><p>Für eigene Domains muss der Betrieb erforderliche DNS-Einträge und Rechte am Domainnamen sicherstellen. Verfügbarkeit und Bedingungen externer Anbieter wie Domain-Registrare, Stripe, OpenAI oder Kommunikationsdienste liegen außerhalb unseres alleinigen Einflusses.</p>
<h2>7. KI-Funktionen</h2><p>KI-Ausgaben sind unterstützende Vorschläge und können unvollständig oder fehlerhaft sein. Der Betrieb muss Ergebnisse vor Nutzung prüfen. LOOKDO verwendet bei der Tätigkeitszuordnung ausschließlich vorhandene Kategorien und Vorlagen; eine fehlende exakte Zuordnung verhindert die Registrierung nicht.</p>
<h2>8. Nutzungsrechte und Daten</h2><p>Der Betrieb behält seine Rechte an eigenen Inhalten und räumt LOOKDO die für Hosting, Darstellung, Sicherung und Übermittlung erforderlichen einfachen Nutzungsrechte für die Vertragsdauer ein. Rechte an Software, Gestaltung und Plattform verbleiben beim Anbieter.</p>
<h2>9. Laufzeit und Kündigung</h2><p>Laufzeit, Verlängerung und Kündigungszeitpunkt richten sich nach dem gebuchten Tarif und den beim Abschluss angezeigten Angaben. Eine Kündigung beendet die kostenpflichtige Nutzung zum bestätigten Zeitpunkt. Das Recht zur außerordentlichen Kündigung aus wichtigem Grund bleibt bestehen.</p>
<h2>10. Gewährleistung und Haftung</h2><p>Es gelten die gesetzlichen Gewährleistungsregeln. Unbeschränkt haften wir bei Vorsatz, grober Fahrlässigkeit, Verletzung von Leben, Körper oder Gesundheit sowie nach zwingendem Produkthaftungsrecht. Bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten ist die Haftung auf den typischen vorhersehbaren Schaden begrenzt.</p>
<h2>11. Änderungen</h2><p>Wir dürfen Funktionen weiterentwickeln, soweit der Vertragszweck erhalten bleibt. Änderungen dieser Bedingungen werden rechtzeitig in Textform mitgeteilt. Soweit gesetzlich erforderlich, wird eine ausdrückliche Zustimmung eingeholt.</p>
<h2>12. Schlussbestimmungen</h2><p>Es gilt deutsches Recht unter Ausschluss des UN-Kaufrechts. Ist der Kunde Kaufmann, juristische Person des öffentlichen Rechts oder öffentlich-rechtliches Sondervermögen, ist der Sitz des Anbieters Gerichtsstand.</p>
HTML,
                'en' => <<<'HTML'
<p><strong>Effective: 26 August 2026</strong></p><h2>1. Scope and provider</h2><p>These terms govern the LOOKDO SaaS agreement between {{operator_name}}, {{operator_address}}, and the registered business. LOOKDO is intended for entrepreneurs and business customers.</p><h2>2. Contract and account</h2><p>A contract is formed when registration or the selected plan is confirmed. Information must be complete and correct, and credentials must be kept confidential.</p><h2>3. Service</h2><p>The selected plan and enabled functions define the service. LOOKDO may provide a platform address, administration, templates, domain connection and, depending on plan, requests, media, messaging, booking, AI or integrations. Continuous uninterrupted availability is not guaranteed.</p><h2>4. Business obligations</h2><p>The business is responsible for its content, services, customer communication and mandatory legal information. Unlawful, misleading, harmful or rights-infringing content is prohibited.</p><h2>5. Prices and payments</h2><p>Prices and billing periods shown at checkout apply. Stripe may process payments. Paid functions may be restricted after reasonable notice if payment is overdue or fails.</p><h2>6. Domains, external services and AI</h2><p>The business must control any connected domain and configure DNS. External providers are governed by their own availability and terms. AI output is assistance only and must be reviewed before use; classification is limited to existing LOOKDO templates and never blocks registration.</p><h2>7. Content and rights</h2><p>The business retains rights in its content and grants LOOKDO the limited rights required to host, display, secure and transmit it during the contract. Platform software and design remain with the provider.</p><h2>8. Term, cancellation and liability</h2><p>Term and cancellation follow the selected plan. Statutory rights to terminate for cause remain. Liability is unlimited for intent, gross negligence, death or personal injury and mandatory product liability; liability for slight negligence affecting essential duties is limited to foreseeable typical loss.</p><h2>9. Final provisions</h2><p>German law applies, excluding the CISG. Where legally permitted, the provider's registered office is the place of jurisdiction.</p>
HTML,
                'ru' => <<<'HTML'
<p><strong>Редакция от 26 августа 2026 года</strong></p><h2>1. Сфера применения и поставщик</h2><p>Условия регулируют использование SaaS-платформы LOOKDO между {{operator_name}}, {{operator_address}}, и зарегистрированным бизнесом. LOOKDO предназначен для предпринимателей и коммерческих клиентов.</p><h2>2. Договор и аккаунт</h2><p>Договор заключается после подтверждения регистрации или выбранного тарифа. Данные должны быть полными и достоверными, а доступ — защищённым.</p><h2>3. Услуги</h2><p>Объём определяется тарифом и включёнными функциями. Это могут быть адрес платформы, управление, шаблоны, домен, заявки, медиа, сообщения, запись, ИИ и интеграции. Непрерывная доступность без технических перерывов не гарантируется.</p><h2>4. Обязанности бизнеса</h2><p>Бизнес отвечает за своё содержимое, предложения, общение с клиентами и обязательную правовую информацию. Запрещены незаконные, вводящие в заблуждение, вредоносные материалы и нарушение чужих прав.</p><h2>5. Цены и оплата</h2><p>Действуют цены и периоды, показанные при оформлении. Платежи может обрабатывать Stripe. При просрочке платные функции могут быть ограничены после надлежащего уведомления.</p><h2>6. Домены, внешние сервисы и ИИ</h2><p>Бизнес должен иметь права на подключаемый домен и настроить DNS. Внешние поставщики работают по собственным условиям. Результаты ИИ являются подсказками и требуют проверки; подбор ограничен существующими шаблонами LOOKDO и не блокирует регистрацию.</p><h2>7. Права на содержимое</h2><p>Бизнес сохраняет права на материалы и предоставляет LOOKDO ограниченные права, необходимые для хранения, показа, защиты и передачи в период договора. Права на ПО и дизайн платформы принадлежат поставщику.</p><h2>8. Срок, прекращение и ответственность</h2><p>Срок и прекращение определяются тарифом. Сохраняется право на досрочное прекращение по существенной причине. Ответственность не ограничивается при умысле, грубой неосторожности, вреде жизни или здоровью; в иных обязательных случаях применяются нормы закона.</p><h2>9. Заключительные положения</h2><p>Применяется право Германии. В допустимых законом случаях местом рассмотрения споров является место нахождения поставщика.</p>
HTML,
                'uk' => <<<'HTML'
<p><strong>Редакція від 26 серпня 2026 року</strong></p><h2>1. Сфера застосування та постачальник</h2><p>Умови регулюють використання SaaS-платформи LOOKDO між {{operator_name}}, {{operator_address}}, і зареєстрованим бізнесом. LOOKDO призначений для підприємців і комерційних клієнтів.</p><h2>2. Договір та обліковий запис</h2><p>Договір укладається після підтвердження реєстрації або тарифу. Дані мають бути повними й правдивими, а доступ — захищеним.</p><h2>3. Послуги</h2><p>Обсяг визначається тарифом та активованими функціями: адреса платформи, керування, шаблони, домен, заявки, медіа, повідомлення, запис, ШІ чи інтеграції. Безперервна доступність без технічних перерв не гарантується.</p><h2>4. Обов'язки бізнесу</h2><p>Бізнес відповідає за свій вміст, пропозиції, спілкування з клієнтами й обов'язкову правову інформацію. Заборонено незаконний, оманливий, шкідливий вміст і порушення чужих прав.</p><h2>5. Ціни та оплата</h2><p>Діють ціни й періоди, показані під час оформлення. Платежі може обробляти Stripe. У разі прострочення платні функції можуть бути обмежені після належного повідомлення.</p><h2>6. Домени, зовнішні сервіси та ШІ</h2><p>Бізнес повинен мати права на домен і налаштувати DNS. Зовнішні постачальники працюють за власними умовами. Результати ШІ є підказками й потребують перевірки; добір обмежений наявними шаблонами LOOKDO та не блокує реєстрацію.</p><h2>7. Права, строк і відповідальність</h2><p>Бізнес зберігає права на матеріали та надає LOOKDO обмежені права для їх зберігання й показу протягом договору. Строк і припинення визначаються тарифом. Обов'язкові правила відповідальності застосовуються без обмежень.</p><h2>8. Прикінцеві положення</h2><p>Застосовується право Німеччини. У дозволених законом випадках місцем розгляду спору є місцезнаходження постачальника.</p>
HTML,
            ],
        ],
        'kontakt' => [
            'title' => ['de' => 'Kontakt', 'en' => 'Contact', 'ru' => 'Контакты', 'uk' => 'Контакти'],
            'content' => [
                'de' => <<<'HTML'
<h2>LOOKDO Support</h2><p>Fragen zu Registrierung, Tarifen, Domains, Abrechnung oder Datenschutz senden Sie bitte an <a href="mailto:{{email}}">{{email}}</a>.</p><h2>Betreiber</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}<br>Telefon: {{phone}}</address><h2>Damit wir schneller helfen können</h2><p>Nennen Sie bitte die E-Mail-Adresse Ihres Kontos, den Namen Ihres Betriebs und – falls vorhanden – Ihre LOOKDO Subdomain. Senden Sie niemals Passwörter, vollständige Zahlungsdaten oder geheime API-Schlüssel per E-Mail.</p><h2>Rechtliche Mitteilungen</h2><p>Rechtlich erhebliche Erklärungen können an die oben genannte ladungsfähige Anschrift oder an <a href="mailto:{{email}}">{{email}}</a> gesendet werden.</p>
HTML,
                'en' => <<<'HTML'
<h2>LOOKDO support</h2><p>For registration, plans, domains, billing or privacy questions, email <a href="mailto:{{email}}">{{email}}</a>.</p><h2>Operator</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}<br>Phone: {{phone}}</address><h2>Help us respond efficiently</h2><p>Include your account email, business name and LOOKDO subdomain where available. Never send passwords, full payment details or secret API keys by email.</p><h2>Legal notices</h2><p>Legally relevant notices may be sent to the service address above or to <a href="mailto:{{email}}">{{email}}</a>.</p>
HTML,
                'ru' => <<<'HTML'
<h2>Поддержка LOOKDO</h2><p>Вопросы о регистрации, тарифах, доменах, оплате или конфиденциальности направляйте на <a href="mailto:{{email}}">{{email}}</a>.</p><h2>Владелец сервиса</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}<br>Телефон: {{phone}}</address><h2>Что указать в обращении</h2><p>Сообщите электронную почту аккаунта, название бизнеса и, если есть, поддомен LOOKDO. Никогда не присылайте пароли, полные платёжные данные или секретные API-ключи.</p><h2>Юридически значимые сообщения</h2><p>Их можно направлять на указанный выше почтовый адрес или на <a href="mailto:{{email}}">{{email}}</a>.</p>
HTML,
                'uk' => <<<'HTML'
<h2>Підтримка LOOKDO</h2><p>Питання щодо реєстрації, тарифів, доменів, оплати чи конфіденційності надсилайте на <a href="mailto:{{email}}">{{email}}</a>.</p><h2>Власник сервісу</h2><address><strong>{{operator_name}}</strong><br>{{operator_address}}<br>Телефон: {{phone}}</address><h2>Що вказати у зверненні</h2><p>Повідомте електронну пошту облікового запису, назву бізнесу та, якщо є, піддомен LOOKDO. Ніколи не надсилайте паролі, повні платіжні дані або секретні API-ключі.</p><h2>Юридично значущі повідомлення</h2><p>Їх можна надсилати на зазначену вище поштову адресу або на <a href="mailto:{{email}}">{{email}}</a>.</p>
HTML,
            ],
        ],
    ],
];
