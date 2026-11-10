<?php

namespace App\Domain\Identity;

/**
 * Guesses a student's gender from the first name, for `students:populate-demographics` only
 * (ADR 0049). It is a guess, never a fact: the command records every field it fills in
 * `student_profiles.demographics_demo_fields`, and the Registrar Head's preview says so.
 *
 * Two levels, kept apart so the command can report how each student was decided:
 *  - `guess()`: the given name is a known Filipino name (the roster's own name pool plus other
 *    common ones). The first token of the name that is known decides, and `Ma.` / `Ma` is Maria.
 *  - `guessFromEnding()`: a weaker rule on the way a name ends, for names not on the list.
 * A name neither level knows returns null and the command falls back to a deterministic pick.
 */
final class FirstNameGenderGuesser
{
    /** @var list<string> */
    private const MALE = [
        // The demo roster's pool (database/seeders/data/filipino-name-pools.php), male sections.
        'jose', 'juan', 'antonio', 'pedro', 'manuel', 'francisco', 'ramon', 'ricardo', 'eduardo', 'fernando',
        'roberto', 'carlos', 'miguel', 'rafael', 'angelo', 'marco', 'paolo', 'gabriel', 'daniel', 'emmanuel',
        'reynaldo', 'rodrigo', 'rogelio', 'romeo', 'ruben', 'salvador', 'santiago', 'teodoro', 'vicente',
        'wilfredo', 'rolando', 'cesar', 'danilo', 'domingo', 'efren', 'elmer', 'ernesto', 'federico',
        'gerardo', 'gil', 'herminio', 'ignacio', 'isagani', 'jaime', 'leonardo', 'lorenzo', 'marcelo',
        'mario', 'nestor', 'oscar', 'pablo', 'quirino', 'renato', 'samuel', 'tomas', 'virgilio', 'alfredo',
        'andres', 'arnel', 'arnold', 'arturo', 'benjamin', 'bernardo', 'crisanto', 'diego', 'edgar', 'edwin',
        'elmo', 'emilio', 'enrico', 'erwin', 'felix', 'florencio', 'gregorio', 'hernan', 'honorio', 'jesus',
        'jovito', 'julio', 'leon', 'leopoldo', 'lucio', 'marlon', 'melchor', 'norberto', 'orlando', 'pio',
        'rey', 'reynante', 'sergio', 'severino', 'sixto', 'timoteo', 'bayani', 'joshua', 'christian', 'mark',
        'john', 'michael', 'kevin', 'kenneth', 'jerome', 'jericho', 'jayson', 'jomar', 'jomel', 'alvin',
        'ariel', 'benedict', 'bryan', 'carlito', 'christopher', 'clarence', 'darwin', 'dexter', 'dominic',
        'earl', 'edison', 'elvis', 'ferdinand', 'gerald', 'glenn', 'harold', 'ivan', 'jansen', 'jeffrey',
        'jonathan', 'justin', 'kirk', 'leandro', 'louie', 'marvin', 'nathaniel', 'noel', 'patrick', 'ronnie',
        'vincent', 'warren', 'wesley', 'dennis', 'bonifacio',
        // Other common Filipino male given names and spellings.
        'jhon', 'jon', 'jhay', 'jay', 'jayvee', 'jerald', 'jerry', 'jimmy', 'joel', 'joey', 'jun', 'junrey',
        'james', 'joseph', 'paul', 'peter', 'philip', 'phillip', 'anthony', 'albert', 'allan', 'alan',
        'alexander', 'andrew', 'aaron', 'adrian', 'adam', 'brian', 'bryle', 'carl', 'charles', 'clifford',
        'dave', 'david', 'dan', 'dino', 'dwight', 'eric', 'erick', 'francis', 'frank', 'fred', 'frederick',
        'gilbert', 'henry', 'ian', 'isaac', 'jake', 'jeremy', 'kurt', 'lance', 'larry', 'lawrence', 'lester',
        'luis', 'mac', 'mathew', 'matthew', 'melvin', 'nathan', 'neil', 'nicholas', 'nico', 'oliver', 'omar',
        'raymond', 'reggie', 'rex', 'rico', 'ritchie', 'robert', 'rocky', 'rodel', 'rodney', 'rommel',
        'rolly', 'ronald', 'roy', 'russel', 'russell', 'ryan', 'ralph', 'renz', 'sean', 'stephen', 'steven',
        'terence', 'theodore', 'thomas', 'tristan', 'victor', 'vince', 'william', 'xavier', 'zachary',
        'alejandro', 'alberto', 'alfonso', 'amado', 'anselmo', 'apolinario', 'armando', 'augusto', 'avelino',
        'bienvenido', 'brando', 'camilo', 'cipriano', 'conrado', 'cornelio', 'dante', 'delfin', 'demetrio',
        'dionisio', 'dionido', 'eleazar', 'eliseo', 'emmanuel', 'ephraim', 'eugenio', 'ezekiel', 'faustino',
        'felipe', 'fidel', 'gener', 'gonzalo', 'guillermo', 'hilario', 'hugo', 'isidro', 'ismael', 'jacinto',
        'jaypee', 'jerwin', 'jessie', 'jhonrey', 'joaquin', 'jonel', 'jovan', 'kristian', 'lemuel', 'lito',
        'lloyd', 'macario', 'maximo', 'mel', 'nelson', 'nilo', 'noli', 'norman', 'obet', 'onofre', 'pacifico',
        'pepito', 'placido', 'rainier', 'randy', 'reniel', 'reyman', 'rhey', 'rizal', 'rodolfo', 'rogel',
        'ronel', 'rosendo', 'rustico', 'sandy', 'sherwin', 'silvestre', 'simon', 'tony', 'valentino',
        'wilson', 'wilmer', 'zaldy', 'zandro', 'ferdie', 'jeric', 'jerico', 'joven', 'joemar', 'jomari',
        'jonas', 'kiel', 'kyle', 'lawrenz', 'lucas', 'luke', 'migs', 'nicolas', 'nigel', 'paulo', 'prince',
        'rainer', 'ramil', 'rhoel', 'rolan', 'romnick', 'ronaldo', 'ruel', 'seth', 'spencer', 'tyron', 'vhon',
        'yves', 'zander',
        // Names found in the roster that the lists above did not cover.
        'donald', 'george', 'jacob', 'richard', 'edward', 'gary', 'scott', 'timothy', 'jack', 'gregory',
        'roderick', 'raul', 'hector', 'brandon', 'jason', 'edgardo', 'arsenio',
    ];

    /** @var list<string> */
    private const FEMALE = [
        // The demo roster's pool, female sections.
        'maria', 'ana', 'rosa', 'carmen', 'teresa', 'josefa', 'luz', 'cristina', 'elena', 'corazon',
        'remedios', 'milagros', 'consuelo', 'esperanza', 'angelica', 'cecilia', 'dolores', 'beatriz',
        'amelia', 'aida', 'alicia', 'amparo', 'anastacia', 'angela', 'antonieta', 'araceli', 'aurora',
        'belen', 'benilda', 'bernadette', 'carolina', 'catalina', 'celia', 'concepcion', 'delia', 'divina',
        'editha', 'elizabeth', 'emilia', 'erlinda', 'estela', 'evangeline', 'fe', 'felicidad', 'flordeliza',
        'gloria', 'gracia', 'guadalupe', 'herminia', 'ignacia', 'imelda', 'irene', 'isabel', 'jacinta',
        'josephine', 'juana', 'julieta', 'leticia', 'lolita', 'lourdes', 'ligaya', 'marilou', 'marites',
        'melinda', 'mercedes', 'minerva', 'natividad', 'nelia', 'nenita', 'norma', 'ofelia', 'perla',
        'purita', 'reyna', 'rosario', 'salome', 'salud', 'socorro', 'soledad', 'susana', 'trinidad',
        'victoria', 'virginia', 'yolanda', 'zenaida', 'angel', 'bea', 'camille', 'charmaine', 'dianne',
        'divine', 'ella', 'faith', 'grace', 'hazel', 'irish', 'janine', 'jasmine', 'jean', 'jenny', 'joy',
        'kaye', 'kim', 'kristine', 'lara', 'lea', 'mae', 'mica', 'nicole', 'patricia', 'princess', 'queenie',
        'rachelle', 'rica', 'riza', 'sharon', 'shiela', 'tina', 'vanessa', 'vivian', 'wendy', 'ysabel',
        'jocelyn', 'liwayway', 'precy',
        // Other common Filipino female given names and spellings.
        'mary', 'maricel', 'marie', 'maribel', 'michelle', 'jennifer', 'jessica', 'jhoanna', 'joanna', 'joan',
        'jane', 'janet', 'julie', 'karen', 'katrina', 'kathleen', 'kristel', 'laura', 'lorraine', 'lyka',
        'margaret', 'maureen', 'mildred', 'mirasol', 'nancy', 'nina', 'pauline', 'rhea', 'rose', 'rosalie',
        'ruth', 'sarah', 'sandra', 'sheila', 'stephanie', 'sofia', 'sophia', 'trisha', 'valerie', 'veronica',
        'yvonne', 'zyra', 'alyssa', 'angeline', 'anna', 'annie', 'april', 'bianca', 'cherry', 'cheryl',
        'chloe', 'claire', 'clarisse', 'daisy', 'denise', 'diana', 'donna', 'eden', 'erica', 'francine',
        'gemma', 'hannah', 'heidi', 'isabella', 'jade', 'jamaica', 'jhen', 'joyce', 'kate', 'katherine',
        'kathy', 'lani', 'lorna', 'lovely', 'mabel', 'maan', 'margie', 'mariel', 'marjorie', 'maryjane',
        'mia', 'nora', 'olivia', 'pamela', 'abigail', 'adelaida', 'agnes', 'aileen', 'alma', 'amor', 'andrea',
        'aileen', 'annabelle', 'anne', 'arlene', 'ashley', 'barbara', 'bernadeth', 'betty', 'blessie',
        'bridget', 'cathy', 'charlene', 'charity', 'cherie', 'cherylyn', 'christine', 'cielo', 'clara',
        'connie', 'cora', 'crisel', 'cynthia', 'danica', 'dessa', 'dolly', 'dorothy', 'edna', 'elaine',
        'eliza', 'ellen', 'emily', 'emma', 'eunice', 'eva', 'fatima', 'florence', 'frances', 'genevieve',
        'georgina', 'gina', 'glaiza', 'gwen', 'helen', 'ivy', 'jackie', 'jaclyn', 'jeanette', 'jemima',
        'jhoana', 'joana', 'johanna', 'jonalyn', 'judy', 'juliet', 'justine', 'karla', 'katie', 'kimberly',
        'krizia', 'kristina', 'lailani', 'leah', 'leila', 'lenie', 'lilia', 'lily', 'linda', 'liza', 'lois',
        'lorena', 'lourdes', 'louisa', 'lynn', 'lyn', 'madonna', 'maita', 'marian', 'marianne', 'marissa',
        'marlene', 'martha', 'mayeth', 'maylene', 'meredith', 'merlyn', 'mikaela', 'monica', 'myrna',
        'nadine', 'naomi', 'nena', 'nerissa', 'noreen', 'orlyn', 'paula', 'pearl', 'phoebe', 'pia', 'rain',
        'rebecca', 'regina', 'rhona', 'rhodora', 'rina', 'roxanne', 'rosemarie', 'rosalinda', 'sabrina',
        'samantha', 'selena', 'shaina', 'shirley', 'stella', 'sunshine', 'tessa', 'thelma',
        'tricia', 'ursula', 'violeta', 'wilma', 'xyza', 'zoe', 'chelsea', 'czarina', 'dahlia', 'elisa',
        'hailey', 'jenelyn', 'jovelyn', 'maegan', 'rosemary', 'shiena', 'zandra',
        // Names found in the roster that the lists above did not cover.
        'carol', 'deborah', 'amy', 'janice', 'lilibeth', 'caridad', 'lisa', 'carolyn', 'susan', 'amanda',
        'brenda', 'debra', 'melissa', 'rachel', 'catherine', 'rochelle', 'rowena', 'melanie', 'evelyn',
        'annaliza', 'teresita', 'josefina', 'ester', 'carmelita', 'marilyn', 'perlita', 'estelita', 'nilda',
        'consolacion', 'leonora', 'rosita', 'vilma',
    ];

    /** @var list<string> */
    private const FEMALE_ENDINGS = ['ine', 'ina', 'elle', 'ette', 'lyn', 'lynn', 'ly', 'ie', 'ia', 'yn', 'ita', 'ela', 'ana', 'ara', 'isa', 'a'];

    /** @var list<string> */
    private const MALE_ENDINGS = ['ito', 'ado', 'ardo', 'berto', 'ldo', 'rdo', 'ino', 'ero', 'ico', 'ando', 'ulo', 'on', 'er', 'an', 'el', 'o'];

    /** @var array<string, true>|null */
    private static ?array $male = null;

    /** @var array<string, true>|null */
    private static ?array $female = null;

    /**
     * A known given name decides; null when no token of the name is on the lists.
     */
    public function guess(?string $firstName): ?Gender
    {
        $male = self::maleSet();
        $female = self::femaleSet();

        foreach ($this->tokens($firstName) as $token) {
            if ($token === 'ma') {
                return Gender::Female;
            }

            $isMale = isset($male[$token]);
            $isFemale = isset($female[$token]);

            if ($isFemale && ! $isMale) {
                return Gender::Female;
            }

            if ($isMale && ! $isFemale) {
                return Gender::Male;
            }
        }

        return null;
    }

    /**
     * A weaker rule for a name that is not on the lists: the last token's ending.
     */
    public function guessFromEnding(?string $firstName): ?Gender
    {
        $tokens = $this->tokens($firstName);

        if ($tokens === []) {
            return null;
        }

        $name = $tokens[0];

        if (strlen($name) < 4) {
            return null;
        }

        foreach (self::FEMALE_ENDINGS as $ending) {
            if (str_ends_with($name, $ending) && strlen($name) > strlen($ending) + 1) {
                return Gender::Female;
            }
        }

        foreach (self::MALE_ENDINGS as $ending) {
            if (str_ends_with($name, $ending) && strlen($name) > strlen($ending) + 1) {
                return Gender::Male;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tokens(?string $firstName): array
    {
        if ($firstName === null) {
            return [];
        }

        $normalized = strtr(mb_strtolower($firstName), ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $parts = preg_split('/[^a-z]+/', $normalized) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return array<string, true>
     */
    private static function maleSet(): array
    {
        return self::$male ??= array_fill_keys(self::MALE, true);
    }

    /**
     * @return array<string, true>
     */
    private static function femaleSet(): array
    {
        return self::$female ??= array_fill_keys(self::FEMALE, true);
    }
}
