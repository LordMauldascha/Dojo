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
        foreach (['Development', 'Project Management', 'Copywriting'] as $title) {
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
            ['title' => 'Webshop Relaunch', 'group' => 'Development', 'description' => 'Shopware relaunch for a fictional furniture retailer: code quality, API documentation and commit conventions.'],
            ['title' => 'API Migration', 'group' => 'Development', 'description' => 'Moving a legacy REST interface to Symfony with API Platform: review, tests and migration notes.'],
            ['title' => 'Sprint Planning', 'group' => 'Project Management', 'description' => 'Planning, estimation and status reports for Scrum teams in client projects.'],
            ['title' => 'Customer Communication', 'group' => 'Project Management', 'description' => 'Proposals, status emails and escalations, worded factually and tailored to the customer.'],
            ['title' => 'Website Content', 'group' => 'Copywriting', 'description' => 'Web copy, meta descriptions and product descriptions for corporate websites.'],
            ['title' => 'Newsletter and Social Media', 'group' => 'Copywriting', 'description' => 'Short texts for newsletters, LinkedIn and Instagram in a consistent tone.'],
        ];
    }

    /** @return list<array{title: string, description: string, content: string, projects: list<string>}> */
    private static function skills(): array
    {
        return [
            [
                'title' => 'PHP Code Review',
                'description' => 'Structured review of Symfony code, sorted by severity.',
                'projects' => ['Webshop Relaunch', 'API Migration'],
                'content' => <<<'TEXT'
                    You are an experienced Symfony developer reviewing PHP code.

                    Procedure:
                    1. Read the whole code before you judge it.
                    2. List findings sorted by severity: Critical, Important, Note.
                    3. Per finding: line or method, the problem in one sentence, a concrete fix.
                    4. Check security first (injection, missing authorization, secrets), then correctness, then style.

                    Rules:
                    - Do not invent problems. If the code is fine, say so in one sentence.
                    - No style comments that a code fixer resolves automatically.
                    - Answer in English, code and identifiers stay as they are.
                    TEXT,
            ],
            [
                'title' => 'Commit Message Writer',
                'description' => 'Creates short English commit messages from a diff.',
                'projects' => ['Webshop Relaunch'],
                'content' => <<<'TEXT'
                    Write a commit message from the given diff.

                    Format:
                    - First line: imperative, English, at most 60 characters, no period at the end.
                    - Blank line, then optionally a short paragraph explaining what the change does and why.

                    Rules:
                    - Describe the effect, not the file names.
                    - Do not mention tools, AI or authors.
                    - Several independent changes: point out that the commit should be split.
                    TEXT,
            ],
            [
                'title' => 'API Endpoint Documentation',
                'description' => 'Documents REST endpoints briefly and consistently.',
                'projects' => ['API Migration'],
                'content' => <<<'TEXT'
                    Create endpoint documentation in Markdown from the route, controller and DTO.

                    Per endpoint:
                    - Method and path
                    - Purpose in one sentence
                    - Parameters and request body as a table (name, type, required, description)
                    - Response status codes with one example each
                    - Notes on authentication and error cases

                    Rules:
                    - Document only what is in the code. If information is missing, write "not apparent".
                    - No examples with real personal data, only synthetic values.
                    TEXT,
            ],
            [
                'title' => 'Migration Checklist',
                'description' => 'Derives a migration checklist from the old and the new interface.',
                'projects' => ['API Migration'],
                'content' => <<<'TEXT'
                    Compare the old and the new interface and create a migration checklist.

                    Structure:
                    1. Breaking changes (paths, fields, status codes, authentication)
                    2. Data that has to be migrated or transformed
                    3. Order of the switchover, including a rollback step
                    4. Tests that must be green before switching

                    Rules:
                    - Every item is a verifiable task, written as an imperative.
                    - Mark unclear items as an open question, do not guess.
                    TEXT,
            ],
            [
                'title' => 'User Story Refinement',
                'description' => 'Sharpens rough requirements into user stories with acceptance criteria.',
                'projects' => ['Sprint Planning'],
                'content' => <<<'TEXT'
                    Turn the given requirement into a user story.

                    Format:
                    - As a [role], I want [goal], so that [benefit].
                    - Acceptance criteria as Given/When/Then, at most six.
                    - Open questions for the customer if the requirement has gaps.
                    - Rough sizing: S, M or L, with one sentence of reasoning.

                    Rules:
                    - Do not prescribe a technical solution.
                    - One story per goal; if the requirement is too big, suggest a split.
                    TEXT,
            ],
            [
                'title' => 'Sprint Status Report',
                'description' => 'Condenses sprint data into a short status report.',
                'projects' => ['Sprint Planning', 'Customer Communication'],
                'content' => <<<'TEXT'
                    Create a sprint status report from the supplied ticket data.

                    Structure:
                    1. Traffic light status (green, yellow, red) with one sentence of reasoning
                    2. Done: at most five items
                    3. In progress and risks: each with the responsible role, not with names
                    4. Next steps with a date in the format YYYY-MM-DD

                    Rules:
                    - Take numbers exactly from the data, do not estimate anything.
                    - At most 150 words.
                    - Factual tone, no sugar-coating of delays.
                    TEXT,
            ],
            [
                'title' => 'Professional Customer Email',
                'description' => 'Words customer emails clearly, politely and action-oriented.',
                'projects' => ['Customer Communication'],
                'content' => <<<'TEXT'
                    Write an email to a customer in English, in a formal register.

                    Structure:
                    - Subject line with the key point
                    - First sentence: occasion or result
                    - Main part: at most three short paragraphs
                    - A clear request or next step with a date (YYYY-MM-DD)
                    - Closing

                    Rules:
                    - Factual and appreciative, no empty phrases.
                    - Name delays and mistakes openly, with a proposed solution.
                    - No internal details, no blaming.
                    TEXT,
            ],
            [
                'title' => 'De-escalate Complaints',
                'description' => 'Replies to angry customer messages calmly and with a focus on solutions.',
                'projects' => ['Customer Communication'],
                'content' => <<<'TEXT'
                    Answer an angry or escalating customer message in English.

                    Procedure:
                    1. Summarize the concern neutrally in one sentence and acknowledge it.
                    2. State the current situation, without justifying.
                    3. Propose a concrete measure with a deadline.
                    4. Offer a conversation (phone or call).

                    Rules:
                    - No sarcasm, no counter-accusations, no promises the team cannot keep.
                    - If the question cannot be answered, say openly what is still being clarified and by when.
                    TEXT,
            ],
            [
                'title' => 'SEO Meta Description',
                'description' => 'Writes meta titles and meta descriptions at the right length.',
                'projects' => ['Website Content'],
                'content' => <<<'TEXT'
                    Create a meta title and a meta description in English for a page.

                    Requirements:
                    - Title: at most 60 characters, main keyword as early as possible.
                    - Description: 140 to 155 characters, with a clear benefit and a call to action.
                    - Work the keyword in naturally, no repetitions.

                    Rules:
                    - Only statements that the page content supports.
                    - State the character count in brackets at the end.
                    - No quotation marks and no emojis.
                    TEXT,
            ],
            [
                'title' => 'Webshop Product Description',
                'description' => 'Writes persuasive, factual product texts.',
                'projects' => ['Website Content'],
                'content' => <<<'TEXT'
                    Write a product description in English from the product data.

                    Structure:
                    - Introduction in two sentences with the main benefit
                    - Three to five features as bullet points, each with the benefit for the customer
                    - Technical data as a short list (dimensions in cm, weight in kg)

                    Rules:
                    - Only facts from the product data, do not invent anything.
                    - No superlatives without proof, no empty phrases like "high-quality" without a reason.
                    - At most 120 words.
                    TEXT,
            ],
            [
                'title' => 'LinkedIn Post Writer',
                'description' => 'Writes LinkedIn posts for a digital agency.',
                'projects' => ['Newsletter and Social Media'],
                'content' => <<<'TEXT'
                    Write a LinkedIn post in English from the given topic.

                    Structure:
                    - First line as a hook, at most 12 words
                    - Three to five short paragraphs, one thought per paragraph
                    - End with a question or a pointer
                    - At most three hashtags at the end

                    Rules:
                    - Personal, factual tone, no buzzwords.
                    - No customer names without approval, no invented numbers.
                    - At most 1,200 characters.
                    TEXT,
            ],
            [
                'title' => 'Newsletter Subject Lines',
                'description' => 'Delivers subject line variants for A/B tests.',
                'projects' => ['Newsletter and Social Media'],
                'content' => <<<'TEXT'
                    Create five subject lines in English for a newsletter topic, for an A/B test.

                    Requirements:
                    - Each line at most 50 characters.
                    - Five different approaches: benefit, question, number, curiosity, urgency.
                    - Plus a preheader for each, at most 90 characters.

                    Rules:
                    - No clickbait, no shouting in capital letters, at most one exclamation mark in total.
                    - The line must deliver what the content promises.
                    TEXT,
            ],
        ];
    }

    /** @return list<array{title: string, description: string, prompt: string, outputCriteria: string, projects: list<string>}> */
    private static function testcases(): array
    {
        return [
            [
                'title' => 'SQL Injection in Repository',
                'description' => 'Checks whether the review finds an obvious SQL injection.',
                'projects' => ['Webshop Relaunch', 'API Migration'],
                'prompt' => <<<'TEXT'
                    Review this method:

                    public function findByName(string $name): array
                    {
                        $sql = "SELECT * FROM product WHERE name = '" . $name . "'";
                        return $this->connection->executeQuery($sql)->fetchAllAssociative();
                    }
                    TEXT,
                'outputCriteria' => 'The answer names the SQL injection as a critical finding. It suggests prepared statements or parameter binding or the QueryBuilder as the fix and shows a corrected version. It does not invent further problems.',
            ],
            [
                'title' => 'Clean Code Without Findings',
                'description' => 'Checks that the review invents nothing for correct code.',
                'projects' => ['Webshop Relaunch'],
                'prompt' => <<<'TEXT'
                    Review this class:

                    final readonly class PriceCalculator
                    {
                        public function __construct(private TaxRateProvider $taxRates) {}

                        public function gross(int $netCents, string $country): int
                        {
                            return (int) round($netCents * (1 + $this->taxRates->get($country)));
                        }
                    }
                    TEXT,
                'outputCriteria' => 'The answer states that there is no critical or important finding. At most one short note (e.g. about a test or about rounding). It does not claim errors that do not exist.',
            ],
            [
                'title' => 'Commit for Cart Fix',
                'description' => 'A single change should result in a short English message.',
                'projects' => ['Webshop Relaunch'],
                'prompt' => <<<'TEXT'
                    Diff:

                    --- a/src/Cart/CartService.php
                    +++ b/src/Cart/CartService.php
                    @@ public function total(Cart $cart): int
                    -    return array_sum(array_map(fn ($i) => $i->price, $cart->items()));
                    +    return array_sum(array_map(fn ($i) => $i->price * $i->quantity, $cart->items()));
                    TEXT,
                'outputCriteria' => 'The first line is in English, imperative, at most 60 characters and has no period. It describes that the quantity is taken into account in the cart total. No mention of AI or tools.',
            ],
            [
                'title' => 'GET Product Endpoint',
                'description' => 'Documentation of a simple read endpoint.',
                'projects' => ['API Migration'],
                'prompt' => <<<'TEXT'
                    Document this endpoint:

                    #[Route('/api/products/{id}', methods: ['GET'])]
                    #[IsGranted('ROLE_USER')]
                    public function show(int $id): JsonResponse
                    {
                        $product = $this->products->find($id) ?? throw $this->createNotFoundException();
                        return $this->json(new ProductDto($product->getSku(), $product->getName(), $product->getPriceCents()));
                    }
                    TEXT,
                'outputCriteria' => 'The documentation names GET /api/products/{id}, the authentication (ROLE_USER), the fields sku, name and priceCents and the status codes 200 and 404 (and 403/401 for missing permissions). Information that is not in the code is marked as not apparent. Example values are synthetic.',
            ],
            [
                'title' => 'Migration Field Renaming',
                'description' => 'Breaking change through renamed fields and a new authentication method.',
                'projects' => ['API Migration'],
                'prompt' => <<<'TEXT'
                    Old: GET /v1/orders returns {"order_id", "total", "state"} and uses a static API key in the header X-Api-Key.
                    New: GET /api/orders returns {"id", "totalCents", "status"} and uses a bearer token (JWT). "total" was a decimal amount in euros, "totalCents" is an integer in cents. The status values "open" and "done" are now called "OPEN" and "COMPLETED".
                    TEXT,
                'outputCriteria' => 'The checklist names all five breaking changes: path, field names, amount (euros to cents), status values and the authentication method. It contains a rollback step and at least one test before the switchover. Every item is worded so that it can be verified.',
            ],
            [
                'title' => 'Detect Missing Information',
                'description' => 'The skill should mark unclear points as an open question, not guess.',
                'projects' => ['API Migration'],
                'prompt' => 'We are migrating the order API. The new version additionally returns a field "channel". We do not know what applied to it in the old version. Create the migration checklist.',
                'outputCriteria' => 'The answer explicitly marks the field "channel" and the unknown legacy logic as an open question. It does not invent a data source or a transformation rule.',
            ],
            [
                'title' => 'Vague Requirement Newsletter Signup',
                'description' => 'A rough requirement becomes a story with acceptance criteria.',
                'projects' => ['Sprint Planning'],
                'prompt' => 'Requirement from the customer: "Visitors should be able to sign up for our newsletter on the website."',
                'outputCriteria' => 'The answer contains a story in the As a/I want/so that format, at most six acceptance criteria in Given/When/Then format and open questions (e.g. double opt-in, consent text, target system). It does not prescribe a technical solution and contains an S/M/L sizing.',
            ],
            [
                'title' => 'Status Report With Delay',
                'description' => 'The report must name the delay openly and take numbers over exactly.',
                'projects' => ['Sprint Planning', 'Customer Communication'],
                'prompt' => 'Sprint 14, duration 2026-11-03 to 2026-11-14. Planned: 21 story points, done: 13. Done: login page, product list, basic cart function. Open: checkout (role backend, blocked by missing credentials from the payment provider), order confirmation (role frontend, not started). The next sprint starts on 2026-11-17.',
                'outputCriteria' => 'The traffic light status is yellow or red with reasoning (13 of 21 story points). The blocker caused by the missing credentials is named as a risk. Responsible people are named only as a role. The date 2026-11-17 is in the format YYYY-MM-DD. At most 150 words, no invented numbers.',
            ],
            [
                'title' => 'Announce Delay',
                'description' => 'Open and solution-oriented email about a postponed go-live.',
                'projects' => ['Customer Communication'],
                'prompt' => 'The go-live of the customer shop moves from 2026-12-01 to 2026-12-15 because the payment provider connection was released later than planned. Write the email to the customer\'s project lead.',
                'outputCriteria' => 'The email is in a formal register, names both dates in the format YYYY-MM-DD and the reason factually. It does not blame the customer or the provider, offers a next step or a conversation and has at most three paragraphs in the main part.',
            ],
            [
                'title' => 'Angry Customer After Outage',
                'description' => 'Calm reply to a sharp complaint after an outage.',
                'projects' => ['Customer Communication'],
                'prompt' => 'Customer message: "Our shop was offline for three hours yesterday and nobody told us. This is unacceptable! I expect an explanation by tomorrow, otherwise we will consider terminating the contract." Known: the cause was a failed hosting update, fixed at 14:20, root cause analysis in progress.',
                'outputCriteria' => 'The answer acknowledges the concern without justifying itself or arguing. It states the current situation (outage fixed, analysis in progress), commits to a deadline for the explanation and offers a phone call. It promises no compensation and blames no one.',
            ],
            [
                'title' => 'Meta Description Service Page',
                'description' => 'Keep to the length requirements and include a call to action.',
                'projects' => ['Website Content'],
                'prompt' => 'Page: "Web Development with Symfony". Content: The agency develops custom web applications and websites based on Symfony and Sulu, from concept to operation. Main keyword: Symfony development.',
                'outputCriteria' => 'Title at most 60 characters, description between 140 and 155 characters. The keyword appears early in the title, the description contains a call to action. The character count is stated and correct. No quotation marks, no emojis, no statements that are not in the content.',
            ],
            [
                'title' => 'Product Text Dining Table',
                'description' => 'Factual description without invented properties.',
                'projects' => ['Website Content'],
                'prompt' => 'Product data: dining table "Nordic", solid oak, oiled. Dimensions 180 x 90 x 75 cm, weight 42 kg. Not extendable. Assembly: the table legs are screwed on, tools are included. Note: not suitable for outdoor use.',
                'outputCriteria' => 'The text has at most 120 words, contains dimensions in cm and weight in kg and uses only the given facts. It names neither an extension function nor outdoor suitability as an advantage and invents no certificates or warranties.',
            ],
            [
                'title' => 'Product Text With Missing Data',
                'description' => 'The skill should invent nothing when the data is thin.',
                'projects' => ['Website Content'],
                'prompt' => 'Product data: armchair "Luna", grey fabric cover. No further information is available.',
                'outputCriteria' => 'The text stays short and names only the model name and the grey fabric cover. It invents no dimensions, materials, weights or comfort promises. Ideally it points out that information is missing.',
            ],
            [
                'title' => 'LinkedIn Post Relaunch',
                'description' => 'A post about a finished project without customer names.',
                'projects' => ['Newsletter and Social Media'],
                'prompt' => 'Topic: We have completed the relaunch of a website for a furniture retailer. Key insight: early content planning saved the schedule. The customer does not want to be named.',
                'outputCriteria' => 'The first line is a hook with at most 12 words. The post has three to five short paragraphs, ends with a question or a pointer, contains at most three hashtags and at most 1,200 characters. The customer is not named, no numbers are invented.',
            ],
            [
                'title' => 'Subject Lines Winter Sale',
                'description' => 'Five variants with length limits and five different approaches.',
                'projects' => ['Newsletter and Social Media'],
                'prompt' => 'Newsletter topic: winter sale in the webshop, 15 percent off all home accessories until 2026-12-31.',
                'outputCriteria' => 'Exactly five subject lines with at most 50 characters, each with a preheader of up to 90 characters. The approaches differ (benefit, question, number, curiosity, urgency). At most one exclamation mark in total, no shouting in capital letters. The 15 percent and the date are correct wherever they appear.',
            ],
        ];
    }

    /** @return list<array{title: string, description: string, project: string, skills: list<string>, testcases: list<string>}> */
    private static function runs(): array
    {
        return [
            [
                'title' => 'Code Review: Claude vs. OpenAI',
                'description' => 'Compares two models in PHP review on a real finding and a clean case.',
                'project' => 'Webshop Relaunch',
                'skills' => ['PHP Code Review'],
                'testcases' => ['SQL Injection in Repository', 'Clean Code Without Findings'],
            ],
            [
                'title' => 'Commit Messages With Small Models',
                'description' => 'Are cheap models enough for short commit messages?',
                'project' => 'Webshop Relaunch',
                'skills' => ['Commit Message Writer'],
                'testcases' => ['Commit for Cart Fix'],
            ],
            [
                'title' => 'Docs and Migration Checklist',
                'description' => 'Tests both skills across three testcases, including incomplete information.',
                'project' => 'API Migration',
                'skills' => ['API Endpoint Documentation', 'Migration Checklist'],
                'testcases' => ['GET Product Endpoint', 'Migration Field Renaming', 'Detect Missing Information'],
            ],
            [
                'title' => 'Review Baseline Without Skill',
                'description' => 'Reference without a skill, to measure the benefit of the review skill.',
                'project' => 'API Migration',
                'skills' => [],
                'testcases' => ['SQL Injection in Repository'],
            ],
            [
                'title' => 'Stories and Status Report',
                'description' => 'User story refinement and sprint report on two models.',
                'project' => 'Sprint Planning',
                'skills' => ['User Story Refinement', 'Sprint Status Report'],
                'testcases' => ['Vague Requirement Newsletter Signup', 'Status Report With Delay'],
            ],
            [
                'title' => 'Tone of Customer Emails',
                'description' => 'Factual tone for delays and complaints, Opus against Sonnet.',
                'project' => 'Customer Communication',
                'skills' => ['Professional Customer Email', 'De-escalate Complaints'],
                'testcases' => ['Announce Delay', 'Angry Customer After Outage'],
            ],
            [
                'title' => 'SEO and Product Texts',
                'description' => 'Length requirements and factual accuracy across three models.',
                'project' => 'Website Content',
                'skills' => ['SEO Meta Description', 'Webshop Product Description'],
                'testcases' => ['Meta Description Service Page', 'Product Text Dining Table', 'Product Text With Missing Data'],
            ],
            [
                'title' => 'LinkedIn and Subject Lines',
                'description' => 'Short texts with hard length limits on two cheap models.',
                'project' => 'Newsletter and Social Media',
                'skills' => ['LinkedIn Post Writer', 'Newsletter Subject Lines'],
                'testcases' => ['LinkedIn Post Relaunch', 'Subject Lines Winter Sale'],
            ],
        ];
    }
}
