<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Group;
use App\Entity\Project;
use App\Entity\Run;
use App\Entity\Skill;
use App\Entity\Testcase;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Bundle\FixturesBundle\ORMFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo state: the users admin and user, three groups, six projects with skills, testcases and runs.
 * Runs have no LLM configuration. Existing groups, projects, skills, testcases, runs and the two demo users are
 * deleted first; other users and the stored API keys stay. Always load with --append, a purge would also
 * delete the API keys and the OAuth clients. See docs/Commands.md.
 */
final class DemoFixtures implements ORMFixtureInterface, FixtureGroupInterface
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('The demo fixtures need the Doctrine ORM.');
        }
        $this->reset($manager);

        $admin = $this->user('admin', UserRole::Admin);
        $user = $this->user('user', UserRole::User);
        $manager->persist($admin);
        $manager->persist($user);

        $groups = [];
        foreach (['Development', 'Projektmanagement', 'Texting'] as $title) {
            $group = new Group();
            $group->setTitle($title);
            $group->addUser($user);
            $manager->persist($group);
            $groups[$title] = $group;
        }

        $projects = [];
        foreach (self::projects() as $data) {
            $project = new Project();
            $project->setTitle($data['title']);
            $project->setDescription($data['description']);
            $project->addGroup($groups[$data['group']]);
            $manager->persist($project);
            $projects[$data['title']] = $project;
        }

        $skills = [];
        foreach (self::skills() as $data) {
            $skill = new Skill($data['title'], $data['description'], $data['content'], $admin);
            foreach ($data['projects'] as $title) {
                $skill->addProject($projects[$title]);
            }
            $manager->persist($skill);
            $skills[$data['title']] = $skill;
        }

        $testcases = [];
        foreach (self::testcases() as $data) {
            $testcase = new Testcase($data['title'], $data['description'], $data['prompt'], $data['outputCriteria'], $admin);
            foreach ($data['projects'] as $title) {
                $testcase->addProject($projects[$title]);
            }
            $manager->persist($testcase);
            $testcases[$data['title']] = $testcase;
        }

        foreach (self::runs() as $data) {
            $run = new Run($projects[$data['project']], $admin);
            $run->setTitle($data['title']);
            $run->setDescription($data['description']);
            $run->setSkills(array_map(static fn (string $title): Skill => $skills[$title], $data['skills']));
            $run->setTestcases(array_map(static fn (string $title): Testcase => $testcases[$title], $data['testcases']));
            $manager->persist($run);
        }

        $manager->flush();
    }

    private function reset(EntityManagerInterface $entityManager): void
    {
        // runs first; versions, results and join rows are removed by the foreign keys (ON DELETE CASCADE)
        foreach ([Run::class, Skill::class, Testcase::class, Project::class, Group::class] as $class) {
            $entityManager->createQuery('DELETE FROM '.$class)->execute();
        }
        $entityManager->createQuery('DELETE FROM '.User::class." u WHERE u.username IN ('admin', 'user')")->execute();
        $entityManager->clear();
    }

    private function user(string $username, UserRole $role): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, $username));

        return $user;
    }

    /** @return list<array{title: string, description: string, group: string}> */
    private static function projects(): array
    {
        return [
            ['title' => 'Webshop Relaunch', 'group' => 'Development', 'description' => 'Shopware-Relaunch eines fiktiven Möbelhändlers: Code-Qualität, API-Dokumentation und Commit-Konventionen.'],
            ['title' => 'API Migration', 'group' => 'Development', 'description' => 'Umstellung einer Legacy-REST-Schnittstelle auf Symfony mit API Platform: Review, Tests und Migrationsnotizen.'],
            ['title' => 'Sprint Planung', 'group' => 'Projektmanagement', 'description' => 'Planung, Schätzung und Statusberichte für Scrum-Teams in Kundenprojekten.'],
            ['title' => 'Kundenkommunikation', 'group' => 'Projektmanagement', 'description' => 'Angebote, Statusmails und Eskalationen sachlich und kundengerecht formulieren.'],
            ['title' => 'Website Content', 'group' => 'Texting', 'description' => 'Webtexte, Meta-Descriptions und Produktbeschreibungen für Unternehmenswebsites.'],
            ['title' => 'Newsletter und Social Media', 'group' => 'Texting', 'description' => 'Kurztexte für Newsletter, LinkedIn und Instagram in konsistentem Tonfall.'],
        ];
    }

    /** @return list<array{title: string, description: string, content: string, projects: list<string>}> */
    private static function skills(): array
    {
        return [
            [
                'title' => 'PHP Code Review',
                'description' => 'Strukturiertes Review von Symfony-Code nach Schweregrad.',
                'projects' => ['Webshop Relaunch', 'API Migration'],
                'content' => <<<'TEXT'
                    Du bist ein erfahrener Symfony-Entwickler und reviewst PHP-Code.

                    Vorgehen:
                    1. Lies den Code vollständig, bevor du urteilst.
                    2. Nenne Befunde nach Schweregrad sortiert: Kritisch, Wichtig, Hinweis.
                    3. Pro Befund: Zeile oder Methode, das Problem in einem Satz, ein konkreter Fix.
                    4. Prüfe zuerst Sicherheit (Injection, fehlende Autorisierung, Secrets), dann Korrektheit, dann Stil.

                    Regeln:
                    - Erfinde keine Probleme. Ist der Code in Ordnung, sage das in einem Satz.
                    - Keine Stilkommentare, die ein Code-Fixer automatisch behebt.
                    - Antworte auf Deutsch, Code und Bezeichner bleiben englisch.
                    TEXT,
            ],
            [
                'title' => 'Commit Message Writer',
                'description' => 'Erzeugt kurze englische Commit-Messages aus einem Diff.',
                'projects' => ['Webshop Relaunch'],
                'content' => <<<'TEXT'
                    Schreibe aus dem gegebenen Diff eine Commit-Message.

                    Format:
                    - Erste Zeile: Imperativ, englisch, maximal 60 Zeichen, kein Punkt am Ende.
                    - Leerzeile, dann optional ein kurzer Absatz, der erklärt, was die Änderung bewirkt und warum.

                    Regeln:
                    - Beschreibe die Wirkung, nicht die Dateinamen.
                    - Keine Erwähnung von Tools, KI oder Autoren.
                    - Mehrere unabhängige Änderungen: weise darauf hin, dass der Commit geteilt werden sollte.
                    TEXT,
            ],
            [
                'title' => 'API Endpoint Documentation',
                'description' => 'Dokumentiert REST-Endpunkte knapp und einheitlich.',
                'projects' => ['API Migration'],
                'content' => <<<'TEXT'
                    Erstelle aus Route, Controller und DTO eine Endpunkt-Dokumentation in Markdown.

                    Pro Endpunkt:
                    - Methode und Pfad
                    - Zweck in einem Satz
                    - Parameter und Request-Body als Tabelle (Name, Typ, Pflicht, Beschreibung)
                    - Antwort-Statuscodes mit je einem Beispiel
                    - Hinweise zu Authentifizierung und Fehlerfällen

                    Regeln:
                    - Nur dokumentieren, was im Code steht. Fehlt eine Information, schreibe "nicht ersichtlich".
                    - Keine Beispiele mit echten Personendaten, nur synthetische Werte.
                    TEXT,
            ],
            [
                'title' => 'Migration Checklist',
                'description' => 'Leitet aus Alt- und Neu-Schnittstelle eine Migrations-Checkliste ab.',
                'projects' => ['API Migration'],
                'content' => <<<'TEXT'
                    Vergleiche die alte und die neue Schnittstelle und erstelle eine Migrations-Checkliste.

                    Gliederung:
                    1. Breaking Changes (Pfade, Felder, Statuscodes, Authentifizierung)
                    2. Daten, die migriert oder transformiert werden müssen
                    3. Reihenfolge der Umstellung inklusive Rollback-Schritt
                    4. Tests, die vor dem Umschalten grün sein müssen

                    Regeln:
                    - Jeder Punkt ist eine prüfbare Aufgabe, formuliert als Imperativ.
                    - Unklare Punkte als Rückfrage kennzeichnen, nicht raten.
                    TEXT,
            ],
            [
                'title' => 'User Story Refinement',
                'description' => 'Schärft grobe Anforderungen zu User Stories mit Akzeptanzkriterien.',
                'projects' => ['Sprint Planung'],
                'content' => <<<'TEXT'
                    Forme die gegebene Anforderung zu einer User Story.

                    Format:
                    - Als [Rolle] möchte ich [Ziel], damit [Nutzen].
                    - Akzeptanzkriterien als Given/When/Then, maximal sechs.
                    - Offene Fragen an den Kunden, falls die Anforderung Lücken hat.
                    - Grobe Einordnung: S, M oder L, mit einem Satz Begründung.

                    Regeln:
                    - Keine technische Lösung vorgeben.
                    - Eine Story pro Ziel; ist die Anforderung zu groß, schlage eine Aufteilung vor.
                    TEXT,
            ],
            [
                'title' => 'Sprint Status Report',
                'description' => 'Fasst Sprint-Daten zu einem kurzen Statusbericht zusammen.',
                'projects' => ['Sprint Planung', 'Kundenkommunikation'],
                'content' => <<<'TEXT'
                    Erstelle aus den gelieferten Ticket-Daten einen Sprint-Statusbericht.

                    Aufbau:
                    1. Ampelstatus (grün, gelb, rot) mit einem Satz Begründung
                    2. Erledigt: höchstens fünf Punkte
                    3. In Arbeit und Risiken: je mit Verantwortlicher Rolle, nicht mit Namen
                    4. Nächste Schritte mit Datum im Format TT.MM.JJJJ

                    Regeln:
                    - Zahlen exakt aus den Daten übernehmen, nichts schätzen.
                    - Maximal 150 Wörter.
                    - Sachlicher Ton, keine Beschönigung bei Verzug.
                    TEXT,
            ],
            [
                'title' => 'Kundenmail Professionell',
                'description' => 'Formuliert Kundenmails klar, höflich und handlungsorientiert.',
                'projects' => ['Kundenkommunikation'],
                'content' => <<<'TEXT'
                    Schreibe eine Mail an einen Kunden auf Deutsch (Sie-Form).

                    Aufbau:
                    - Betreff mit dem Kernpunkt
                    - Erster Satz: Anlass oder Ergebnis
                    - Hauptteil: maximal drei kurze Absätze
                    - Klare Bitte oder nächster Schritt mit Datum (TT.MM.JJJJ)
                    - Grußformel

                    Regeln:
                    - Sachlich und wertschätzend, keine Floskeln.
                    - Verzögerungen und Fehler offen benennen, mit Lösungsvorschlag.
                    - Keine internen Details, keine Schuldzuweisungen.
                    TEXT,
            ],
            [
                'title' => 'Eskalation Deeskalieren',
                'description' => 'Antwortet auf verärgerte Kundenanfragen ruhig und lösungsorientiert.',
                'projects' => ['Kundenkommunikation'],
                'content' => <<<'TEXT'
                    Beantworte eine verärgerte oder eskalierende Kundennachricht auf Deutsch.

                    Vorgehen:
                    1. Anliegen in einem Satz neutral zusammenfassen und anerkennen.
                    2. Sachstand benennen, ohne Rechtfertigung.
                    3. Konkrete Maßnahme mit Termin vorschlagen.
                    4. Gesprächsangebot machen (Telefon oder Call).

                    Regeln:
                    - Kein Sarkasmus, keine Gegenvorwürfe, keine Versprechen, die das Team nicht halten kann.
                    - Kann die Frage nicht beantwortet werden, nenne offen, was noch geklärt wird und bis wann.
                    TEXT,
            ],
            [
                'title' => 'SEO Meta Description',
                'description' => 'Schreibt Meta-Titel und Meta-Descriptions in der richtigen Länge.',
                'projects' => ['Website Content'],
                'content' => <<<'TEXT'
                    Erstelle zu einer Seite einen Meta-Titel und eine Meta-Description auf Deutsch.

                    Vorgaben:
                    - Titel: maximal 60 Zeichen, Hauptkeyword möglichst früh.
                    - Description: 140 bis 155 Zeichen, mit einem klaren Nutzen und einer Handlungsaufforderung.
                    - Keyword natürlich einbauen, keine Wiederholungen.

                    Regeln:
                    - Nur Aussagen, die der Seiteninhalt belegt.
                    - Zeichenanzahl am Ende in Klammern angeben.
                    - Keine Anführungszeichen und keine Emojis.
                    TEXT,
            ],
            [
                'title' => 'Produktbeschreibung Webshop',
                'description' => 'Schreibt verkaufsstarke, faktentreue Produkttexte.',
                'projects' => ['Website Content'],
                'content' => <<<'TEXT'
                    Schreibe aus den Produktdaten eine Produktbeschreibung auf Deutsch.

                    Aufbau:
                    - Einleitung in zwei Sätzen mit dem Hauptnutzen
                    - Drei bis fünf Merkmale als Stichpunkte, jeweils mit Nutzen für die Kundin oder den Kunden
                    - Technische Daten als kurze Liste (Maße in cm, Gewicht in kg)

                    Regeln:
                    - Nur Fakten aus den Produktdaten, nichts hinzuerfinden.
                    - Keine Superlative ohne Beleg, keine Floskeln wie "hochwertig" ohne Begründung.
                    - Maximal 120 Wörter.
                    TEXT,
            ],
            [
                'title' => 'LinkedIn Post Writer',
                'description' => 'Schreibt LinkedIn-Posts für eine Digitalagentur.',
                'projects' => ['Newsletter und Social Media'],
                'content' => <<<'TEXT'
                    Schreibe einen LinkedIn-Post auf Deutsch aus dem gegebenen Thema.

                    Aufbau:
                    - Erste Zeile als Hook, höchstens 12 Wörter
                    - Drei bis fünf kurze Absätze, ein Gedanke pro Absatz
                    - Schluss mit einer Frage oder einem Hinweis
                    - Höchstens drei Hashtags am Ende

                    Regeln:
                    - Persönlicher, sachlicher Ton, keine Buzzwords.
                    - Keine Kundennamen ohne Freigabe, keine erfundenen Zahlen.
                    - Maximal 1.200 Zeichen.
                    TEXT,
            ],
            [
                'title' => 'Newsletter Betreffzeilen',
                'description' => 'Liefert Betreffzeilen-Varianten für A/B-Tests.',
                'projects' => ['Newsletter und Social Media'],
                'content' => <<<'TEXT'
                    Erzeuge zu einem Newsletter-Thema fünf Betreffzeilen auf Deutsch für einen A/B-Test.

                    Vorgaben:
                    - Jede Zeile höchstens 50 Zeichen.
                    - Fünf unterschiedliche Ansätze: Nutzen, Frage, Zahl, Neugier, Dringlichkeit.
                    - Dazu je ein Preheader mit höchstens 90 Zeichen.

                    Regeln:
                    - Kein Clickbait, kein Großbuchstaben-Geschrei, höchstens ein Ausrufezeichen insgesamt.
                    - Die Zeile muss halten, was der Inhalt verspricht.
                    TEXT,
            ],
        ];
    }

    /** @return list<array{title: string, description: string, prompt: string, outputCriteria: string, projects: list<string>}> */
    private static function testcases(): array
    {
        return [
            [
                'title' => 'SQL Injection im Repository',
                'description' => 'Prüft, ob das Review eine offensichtliche SQL-Injection findet.',
                'projects' => ['Webshop Relaunch', 'API Migration'],
                'prompt' => <<<'TEXT'
                    Reviewe diese Methode:

                    public function findByName(string $name): array
                    {
                        $sql = "SELECT * FROM product WHERE name = '" . $name . "'";
                        return $this->connection->executeQuery($sql)->fetchAllAssociative();
                    }
                    TEXT,
                'outputCriteria' => 'Die Antwort benennt die SQL-Injection als kritischen Befund. Sie schlägt Prepared Statements bzw. Parameter-Binding oder den QueryBuilder als Fix vor und zeigt eine korrigierte Variante. Sie erfindet keine weiteren Probleme.',
            ],
            [
                'title' => 'Sauberer Code ohne Befund',
                'description' => 'Prüft, dass das Review bei korrektem Code nichts erfindet.',
                'projects' => ['Webshop Relaunch'],
                'prompt' => <<<'TEXT'
                    Reviewe diese Klasse:

                    final readonly class PriceCalculator
                    {
                        public function __construct(private TaxRateProvider $taxRates) {}

                        public function gross(int $netCents, string $country): int
                        {
                            return (int) round($netCents * (1 + $this->taxRates->get($country)));
                        }
                    }
                    TEXT,
                'outputCriteria' => 'Die Antwort stellt fest, dass kein kritischer oder wichtiger Befund vorliegt. Höchstens ein kurzer Hinweis (z. B. zu einem Test oder zur Rundung). Sie behauptet keine Fehler, die nicht existieren.',
            ],
            [
                'title' => 'Commit für Warenkorb-Fix',
                'description' => 'Eine einzelne Änderung soll eine kurze englische Message ergeben.',
                'projects' => ['Webshop Relaunch'],
                'prompt' => <<<'TEXT'
                    Diff:

                    --- a/src/Cart/CartService.php
                    +++ b/src/Cart/CartService.php
                    @@ public function total(Cart $cart): int
                    -    return array_sum(array_map(fn ($i) => $i->price, $cart->items()));
                    +    return array_sum(array_map(fn ($i) => $i->price * $i->quantity, $cart->items()));
                    TEXT,
                'outputCriteria' => 'Die erste Zeile ist englisch, im Imperativ, höchstens 60 Zeichen und ohne Punkt. Sie beschreibt, dass die Menge bei der Warenkorbsumme berücksichtigt wird. Keine Erwähnung von KI oder Tools.',
            ],
            [
                'title' => 'Endpunkt GET Produkt',
                'description' => 'Dokumentation eines einfachen Lese-Endpunkts.',
                'projects' => ['API Migration'],
                'prompt' => <<<'TEXT'
                    Dokumentiere diesen Endpunkt:

                    #[Route('/api/products/{id}', methods: ['GET'])]
                    #[IsGranted('ROLE_USER')]
                    public function show(int $id): JsonResponse
                    {
                        $product = $this->products->find($id) ?? throw $this->createNotFoundException();
                        return $this->json(new ProductDto($product->getSku(), $product->getName(), $product->getPriceCents()));
                    }
                    TEXT,
                'outputCriteria' => 'Die Doku nennt GET /api/products/{id}, die Authentifizierung (ROLE_USER), die Felder sku, name und priceCents sowie die Statuscodes 200 und 404 (und 403/401 für fehlende Rechte). Angaben, die nicht im Code stehen, sind als nicht ersichtlich markiert. Beispielwerte sind synthetisch.',
            ],
            [
                'title' => 'Migration Feldumbenennung',
                'description' => 'Breaking Change durch umbenannte Felder und neues Auth-Verfahren.',
                'projects' => ['API Migration'],
                'prompt' => <<<'TEXT'
                    Alt: GET /v1/orders liefert {"order_id", "total", "state"} und nutzt einen statischen API-Key im Header X-Api-Key.
                    Neu: GET /api/orders liefert {"id", "totalCents", "status"} und nutzt Bearer-Token (JWT). "total" war ein Dezimalbetrag in Euro, "totalCents" ist eine ganze Zahl in Cent. Die Status-Werte "open" und "done" heißen jetzt "OPEN" und "COMPLETED".
                    TEXT,
                'outputCriteria' => 'Die Checkliste nennt alle fünf Breaking Changes: Pfad, Feldnamen, Betrag (Euro zu Cent), Statuswerte und das Auth-Verfahren. Sie enthält einen Rollback-Schritt und mindestens einen Test vor dem Umschalten. Jeder Punkt ist prüfbar formuliert.',
            ],
            [
                'title' => 'Fehlende Angaben erkennen',
                'description' => 'Skill soll unklare Punkte als Rückfrage kennzeichnen, nicht raten.',
                'projects' => ['API Migration'],
                'prompt' => 'Wir stellen die Bestell-API um. Die neue Version liefert zusätzlich ein Feld "channel". Was in der alten Version dafür galt, wissen wir nicht. Erstelle die Migrations-Checkliste.',
                'outputCriteria' => 'Die Antwort markiert das Feld "channel" und die unbekannte Altlogik ausdrücklich als offene Rückfrage. Sie erfindet keine Datenherkunft oder Transformationsregel.',
            ],
            [
                'title' => 'Vage Anforderung Newsletter-Anmeldung',
                'description' => 'Aus einer groben Anforderung entsteht eine Story mit Akzeptanzkriterien.',
                'projects' => ['Sprint Planung'],
                'prompt' => 'Anforderung vom Kunden: "Besucher sollen sich auf der Website für unseren Newsletter anmelden können."',
                'outputCriteria' => 'Die Antwort enthält eine Story im Format Als/möchte ich/damit, höchstens sechs Akzeptanzkriterien im Given/When/Then-Format und offene Fragen (z. B. Double-Opt-In, Einwilligungstext, Zielsystem). Sie gibt keine technische Lösung vor und enthält eine S/M/L-Einordnung.',
            ],
            [
                'title' => 'Statusbericht mit Verzug',
                'description' => 'Der Bericht muss Verzug offen benennen und Zahlen exakt übernehmen.',
                'projects' => ['Sprint Planung', 'Kundenkommunikation'],
                'prompt' => 'Sprint 14, Laufzeit 03.11.2026 bis 14.11.2026. Geplant: 21 Story Points, erledigt: 13. Erledigt: Login-Seite, Produktliste, Warenkorb-Grundfunktion. Offen: Checkout (Rolle Backend, blockiert durch fehlende Zugangsdaten des Zahlungsanbieters), Bestellbestätigung (Rolle Frontend, nicht begonnen). Nächster Sprint startet am 17.11.2026.',
                'outputCriteria' => 'Ampelstatus ist gelb oder rot mit Begründung (13 von 21 Story Points). Die Blockade durch die fehlenden Zugangsdaten ist als Risiko genannt. Verantwortliche sind nur als Rolle genannt. Das Datum 17.11.2026 steht im Format TT.MM.JJJJ. Höchstens 150 Wörter, keine erfundenen Zahlen.',
            ],
            [
                'title' => 'Verzögerung ankündigen',
                'description' => 'Offene und lösungsorientierte Mail zu einem verschobenen Livegang.',
                'projects' => ['Kundenkommunikation'],
                'prompt' => 'Der Livegang des Kundenshops verschiebt sich vom 01.12.2026 auf den 15.12.2026, weil die Anbindung des Zahlungsanbieters später als geplant freigegeben wurde. Schreibe die Mail an die Projektleitung des Kunden.',
                'outputCriteria' => 'Die Mail ist in Sie-Form, nennt beide Daten im Format TT.MM.JJJJ und den Grund sachlich. Sie schiebt dem Kunden oder Anbieter keine Schuld zu, bietet einen nächsten Schritt oder ein Gespräch an und hat höchstens drei Absätze im Hauptteil.',
            ],
            [
                'title' => 'Verärgerter Kunde wegen Ausfall',
                'description' => 'Ruhige Antwort auf eine scharfe Beschwerde nach einem Ausfall.',
                'projects' => ['Kundenkommunikation'],
                'prompt' => 'Kundennachricht: "Unser Shop war gestern drei Stunden offline und niemand hat uns informiert. Das ist inakzeptabel! Ich erwarte bis morgen eine Erklärung, sonst überlegen wir uns, den Vertrag zu kündigen." Bekannt: Ursache war ein fehlgeschlagenes Update des Hostings, behoben um 14:20 Uhr, Ursachenanalyse läuft.',
                'outputCriteria' => 'Die Antwort erkennt das Anliegen an, ohne sich zu rechtfertigen oder zu streiten. Sie nennt den Sachstand (Ausfall behoben, Analyse läuft), sagt zu, bis wann die Erklärung kommt, und bietet ein Telefonat an. Sie verspricht keine Entschädigung und gibt keine Schuldzuweisung.',
            ],
            [
                'title' => 'Meta Description Leistungsseite',
                'description' => 'Längenvorgaben und Handlungsaufforderung einhalten.',
                'projects' => ['Website Content'],
                'prompt' => 'Seite: "Webentwicklung mit Symfony". Inhalt: Die Agentur entwickelt individuelle Webanwendungen und Websites auf Basis von Symfony und Sulu, von der Konzeption bis zum Betrieb. Hauptkeyword: Symfony Entwicklung.',
                'outputCriteria' => 'Titel höchstens 60 Zeichen, Description zwischen 140 und 155 Zeichen. Das Keyword steht früh im Titel, die Description enthält eine Handlungsaufforderung. Zeichenanzahl ist angegeben und stimmt. Keine Anführungszeichen, keine Emojis, keine Aussagen, die nicht im Inhalt stehen.',
            ],
            [
                'title' => 'Produkttext Esstisch',
                'description' => 'Faktentreue Beschreibung ohne erfundene Eigenschaften.',
                'projects' => ['Website Content'],
                'prompt' => 'Produktdaten: Esstisch "Nordic", Eiche massiv, geölt. Maße 180 x 90 x 75 cm, Gewicht 42 kg. Ausziehbar nicht möglich. Montage: Tischbeine werden angeschraubt, Werkzeug liegt bei. Seitenhinweis: Nicht für den Außenbereich geeignet.',
                'outputCriteria' => 'Der Text hat maximal 120 Wörter, enthält Maße in cm und Gewicht in kg und verwendet nur die gegebenen Fakten. Er nennt weder Ausziehfunktion noch Outdoor-Eignung als Vorteil und erfindet keine Zertifikate oder Garantien.',
            ],
            [
                'title' => 'Produkttext mit fehlenden Daten',
                'description' => 'Skill soll bei dünnen Daten nichts erfinden.',
                'projects' => ['Website Content'],
                'prompt' => 'Produktdaten: Sessel "Luna", Stoffbezug grau. Mehr Informationen liegen nicht vor.',
                'outputCriteria' => 'Der Text bleibt kurz und nennt nur Modellname und grauen Stoffbezug. Er erfindet keine Maße, Materialien, Gewichte oder Komfort-Versprechen. Idealerweise weist er darauf hin, dass Angaben fehlen.',
            ],
            [
                'title' => 'LinkedIn Post Relaunch',
                'description' => 'Ein Post zu einem abgeschlossenen Projekt ohne Kundennamen.',
                'projects' => ['Newsletter und Social Media'],
                'prompt' => 'Thema: Wir haben den Relaunch einer Website für einen Händler aus der Möbelbranche abgeschlossen. Wichtigste Erkenntnis: Frühe Inhalte-Planung hat den Zeitplan gerettet. Kunde möchte nicht genannt werden.',
                'outputCriteria' => 'Die erste Zeile ist ein Hook mit höchstens 12 Wörtern. Der Post hat drei bis fünf kurze Absätze, endet mit Frage oder Hinweis, enthält höchstens drei Hashtags und höchstens 1.200 Zeichen. Der Kunde wird nicht genannt, es werden keine Zahlen erfunden.',
            ],
            [
                'title' => 'Betreffzeilen Winteraktion',
                'description' => 'Fünf Varianten mit Längenlimits und fünf unterschiedlichen Ansätzen.',
                'projects' => ['Newsletter und Social Media'],
                'prompt' => 'Newsletter-Thema: Winteraktion im Webshop, 15 Prozent auf alle Wohnaccessoires bis 31.12.2026.',
                'outputCriteria' => 'Genau fünf Betreffzeilen mit höchstens 50 Zeichen, jede mit Preheader bis 90 Zeichen. Die Ansätze unterscheiden sich (Nutzen, Frage, Zahl, Neugier, Dringlichkeit). Höchstens ein Ausrufezeichen insgesamt, kein Großbuchstaben-Geschrei. Die 15 Prozent und das Datum sind korrekt, wo sie vorkommen.',
            ],
        ];
    }

    /** @return list<array{title: string, description: string, project: string, skills: list<string>, testcases: list<string>}> */
    private static function runs(): array
    {
        return [
            [
                'title' => 'Code Review: Claude vs. OpenAI',
                'description' => 'Vergleicht zwei Modelle beim PHP-Review auf einen echten Befund und einen sauberen Fall.',
                'project' => 'Webshop Relaunch',
                'skills' => ['PHP Code Review'],
                'testcases' => ['SQL Injection im Repository', 'Sauberer Code ohne Befund'],
            ],
            [
                'title' => 'Commit Messages mit kleinen Modellen',
                'description' => 'Reichen günstige Modelle für kurze Commit-Messages?',
                'project' => 'Webshop Relaunch',
                'skills' => ['Commit Message Writer'],
                'testcases' => ['Commit für Warenkorb-Fix'],
            ],
            [
                'title' => 'Doku und Migrations-Checkliste',
                'description' => 'Prüft beide Skills über drei Testcases, auch mit unvollständigen Angaben.',
                'project' => 'API Migration',
                'skills' => ['API Endpoint Documentation', 'Migration Checklist'],
                'testcases' => ['Endpunkt GET Produkt', 'Migration Feldumbenennung', 'Fehlende Angaben erkennen'],
            ],
            [
                'title' => 'Review Basislinie ohne Skill',
                'description' => 'Referenz ohne Skill, um den Nutzen des Review-Skills zu messen.',
                'project' => 'API Migration',
                'skills' => [],
                'testcases' => ['SQL Injection im Repository'],
            ],
            [
                'title' => 'Stories und Statusbericht',
                'description' => 'User-Story-Refinement und Sprint-Bericht auf zwei Modellen.',
                'project' => 'Sprint Planung',
                'skills' => ['User Story Refinement', 'Sprint Status Report'],
                'testcases' => ['Vage Anforderung Newsletter-Anmeldung', 'Statusbericht mit Verzug'],
            ],
            [
                'title' => 'Tonalität Kundenmails',
                'description' => 'Sachlicher Ton bei Verzug und Beschwerde, Opus gegen Sonnet.',
                'project' => 'Kundenkommunikation',
                'skills' => ['Kundenmail Professionell', 'Eskalation Deeskalieren'],
                'testcases' => ['Verzögerung ankündigen', 'Verärgerter Kunde wegen Ausfall'],
            ],
            [
                'title' => 'SEO und Produkttexte',
                'description' => 'Längenvorgaben und Faktentreue über drei Modelle.',
                'project' => 'Website Content',
                'skills' => ['SEO Meta Description', 'Produktbeschreibung Webshop'],
                'testcases' => ['Meta Description Leistungsseite', 'Produkttext Esstisch', 'Produkttext mit fehlenden Daten'],
            ],
            [
                'title' => 'LinkedIn und Betreffzeilen',
                'description' => 'Kurztexte mit harten Längenlimits auf zwei günstigen Modellen.',
                'project' => 'Newsletter und Social Media',
                'skills' => ['LinkedIn Post Writer', 'Newsletter Betreffzeilen'],
                'testcases' => ['LinkedIn Post Relaunch', 'Betreffzeilen Winteraktion'],
            ],
        ];
    }
}
