<?php

/**
 * Pools of plausible Filipino given names and surnames used to generate
 * synthetic student identities for the demo enrollment roster (see
 * App\Domain\Identity\StudentIdentityGenerator).
 *
 * Names are drawn from common Philippine naming conventions — a mix of
 * Spanish-origin, indigenous/native, and English-origin given names and
 * surnames — and are not tied to any real person. Purely cosmetic data,
 * matching the synthetic `@grc.test` identities used elsewhere in this
 * codebase.
 *
 * @return array{given: list<string>, surname: list<string>}
 */
return [
    'given' => [
        // Male, Spanish-origin
        'Jose', 'Juan', 'Antonio', 'Pedro', 'Manuel', 'Francisco', 'Ramon',
        'Ricardo', 'Eduardo', 'Fernando', 'Roberto', 'Carlos', 'Miguel',
        'Rafael', 'Angelo', 'Marco', 'Paolo', 'Gabriel', 'Daniel', 'Emmanuel',
        'Reynaldo', 'Rodrigo', 'Rogelio', 'Romeo', 'Ruben', 'Salvador',
        'Santiago', 'Teodoro', 'Vicente', 'Wilfredo', 'Rolando', 'Cesar',
        'Danilo', 'Domingo', 'Efren', 'Elmer', 'Ernesto', 'Federico',
        'Gerardo', 'Gil', 'Herminio', 'Ignacio', 'Isagani', 'Jaime',
        'Leonardo', 'Lorenzo', 'Marcelo', 'Mario', 'Nestor', 'Oscar', 'Pablo',
        'Quirino', 'Renato', 'Samuel', 'Tomas', 'Virgilio', 'Alfredo',
        'Andres', 'Arnel', 'Arnold', 'Arturo', 'Benjamin', 'Bernardo',
        'Crisanto', 'Diego', 'Edgar', 'Edwin', 'Elmo', 'Emilio', 'Enrico',
        'Erwin', 'Felix', 'Florencio', 'Gregorio', 'Hernan', 'Honorio',
        'Jesus', 'Jovito', 'Julio', 'Leon', 'Leopoldo', 'Lucio', 'Marlon',
        'Melchor', 'Norberto', 'Orlando', 'Pio', 'Rey', 'Reynante', 'Sergio',
        'Severino', 'Sixto', 'Timoteo', 'Bayani',
        // Male, English/modern-origin
        'Joshua', 'Christian', 'Mark', 'John', 'Michael', 'Kevin', 'Kenneth',
        'Jerome', 'Jericho', 'Jayson', 'Jomar', 'Jomel', 'Alvin', 'Ariel',
        'Benedict', 'Bryan', 'Carlito', 'Christopher', 'Clarence', 'Darwin',
        'Dexter', 'Dominic', 'Earl', 'Edison', 'Elvis', 'Ferdinand', 'Gerald',
        'Glenn', 'Harold', 'Ivan', 'Jansen', 'Jeffrey', 'Jonathan', 'Justin',
        'Kirk', 'Leandro', 'Louie', 'Marvin', 'Nathaniel', 'Noel', 'Patrick',
        'Ronnie', 'Vincent', 'Warren', 'Wesley', 'Dennis', 'Bonifacio',
        // Female, Spanish-origin
        'Maria', 'Ana', 'Rosa', 'Carmen', 'Teresa', 'Josefa', 'Luz',
        'Cristina', 'Elena', 'Corazon', 'Remedios', 'Milagros', 'Consuelo',
        'Esperanza', 'Angelica', 'Cecilia', 'Dolores', 'Beatriz', 'Amelia',
        'Aida', 'Alicia', 'Amparo', 'Anastacia', 'Angela', 'Antonieta',
        'Araceli', 'Aurora', 'Belen', 'Benilda', 'Bernadette', 'Carolina',
        'Catalina', 'Celia', 'Concepcion', 'Delia', 'Divina', 'Editha',
        'Elizabeth', 'Emilia', 'Erlinda', 'Estela', 'Evangeline', 'Fe',
        'Felicidad', 'Flordeliza', 'Gloria', 'Gracia', 'Guadalupe',
        'Herminia', 'Ignacia', 'Imelda', 'Irene', 'Isabel', 'Jacinta',
        'Josephine', 'Juana', 'Julieta', 'Leticia', 'Lolita', 'Lourdes',
        'Ligaya', 'Marilou', 'Marites', 'Melinda', 'Mercedes', 'Minerva',
        'Natividad', 'Nelia', 'Nenita', 'Norma', 'Ofelia', 'Perla', 'Purita',
        'Reyna', 'Rosario', 'Salome', 'Salud', 'Socorro', 'Soledad',
        'Susana', 'Trinidad', 'Victoria', 'Virginia', 'Yolanda', 'Zenaida',
        // Female, English/modern-origin
        'Angel', 'Bea', 'Camille', 'Charmaine', 'Dianne', 'Divine', 'Ella',
        'Faith', 'Grace', 'Hazel', 'Irish', 'Janine', 'Jasmine', 'Jean',
        'Jenny', 'Joy', 'Kaye', 'Kim', 'Kristine', 'Lara', 'Lea', 'Mae',
        'Mica', 'Nicole', 'Patricia', 'Princess', 'Queenie', 'Rachelle',
        'Rica', 'Riza', 'Sharon', 'Shiela', 'Tina', 'Vanessa', 'Vivian',
        'Wendy', 'Ysabel', 'Jocelyn', 'Liwayway', 'Precy',
    ],
    'surname' => [
        // Spanish-origin
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Torres',
        'Flores', 'Ramos', 'Mendoza', 'Villanueva', 'Aquino', 'Fernandez',
        'De Leon', 'Del Rosario', 'Gonzales', 'Rivera', 'Aguilar', 'Salazar',
        'Domingo', 'Castillo', 'Navarro', 'Pascual', 'Manalo', 'Marquez',
        'Rosario', 'Salvador', 'Santiago', 'Vergara', 'Roxas', 'Laurel',
        'Alvarez', 'Bernardo', 'Cabrera', 'Castro', 'Concepcion', 'Correa',
        'Diaz', 'Dizon', 'Espinosa', 'Estrella', 'Fajardo', 'Feliciano',
        'Gatdula', 'Gomez', 'Guerrero', 'Herrera', 'Jimenez', 'Lopez',
        'Macaraeg', 'Marcelo', 'Medina', 'Mercado', 'Morales', 'Ortega',
        'Padilla', 'Pena', 'Perez', 'Quiambao', 'Ramirez', 'Robles',
        'Rodriguez', 'Romero', 'Salonga', 'Sanchez', 'Serrano', 'Sison',
        'Soriano', 'Suarez', 'Valdez', 'Valencia', 'Vargas', 'Vasquez',
        'Vega', 'Velasco', 'Villaflor', 'Villareal', 'Zamora', 'Zaragoza',
        'De Guzman', 'De Vera', 'De los Santos', 'Del Mundo', 'Encarnacion',
        'Nepomuceno', 'Olivares', 'Peralta', 'Trinidad', 'Villamor',
        'Villegas', 'Aranda', 'Belmonte', 'Cortez', 'Enriquez',
        // Indigenous / native-origin
        'Dimaculangan', 'Panganiban', 'Manalastas', 'Katindig', 'Balagtas',
        'Dalisay', 'Dimayuga', 'Gatchalian', 'Lakandula', 'Malabanan',
        'Magsino', 'Bagsic', 'Batungbakal', 'Calungsod', 'Dagohoy',
        'Dimalanta', 'Dumlao', 'Gapasin', 'Guiao', 'Kalaw', 'Lumbera',
        'Macabulos', 'Macaspac', 'Malvar', 'Manansala', 'Manlapaz',
        'Pangilinan', 'Pundaquit', 'Quimpo', 'Silangan', 'Tagle', 'Tamayo',
        'Umali', 'Ungson', 'Balatbat', 'Buenaventura', 'Balita', 'Baltazar',
        'Bumatay', 'Cabahug', 'Dagdag', 'Danao', 'Dungog', 'Galang',
        'Ganding', 'Habana', 'Ilagan', 'Lacsamana', 'Lumbab', 'Malinis',
        'Mangubat', 'Padua', 'Pagulayan', 'Pinlac', 'Punzalan', 'Quiazon',
        'Quilang', 'Sagum', 'Tagumpay', 'Tuazon', 'Villaruel',
        // Chinese-Filipino-origin
        'Yap', 'Tan', 'Sy', 'Lim', 'Ong', 'Go', 'Chua', 'Uy', 'Cojuangco',
        'Locsin', 'Chan', 'Chiong', 'Cuenco', 'Dee', 'Gaw', 'Ko', 'Lao',
        'Sia', 'Tiu', 'Tuason',
        // English/American-influenced
        'Fisher', 'Grant', 'Hall', 'Jackson', 'King', 'Lewis', 'Marshall',
        'Miller', 'Parker', 'Reid', 'Russell', 'Scott', 'Taylor', 'Turner',
        'Walker', 'Ward', 'Watson', 'Wright', 'Young', 'Foster',
        // Additional common regional surnames
        'Abad', 'Alcantara', 'Amurao', 'Andrada', 'Angeles', 'Antonio',
        'Arceo', 'Arellano', 'Argosino', 'Bagayas', 'Bartolome', 'Batac',
        'Buenavista', 'Cabanting', 'Calderon', 'Cariaso', 'Corpuz', 'Cuevas',
        'David', 'Delos Reyes', 'Escobar', 'Espino', 'Estioko', 'Evangelista',
        'Galvez', 'Ignacio', 'Isidro', 'Javier', 'Lacson', 'Leyco', 'Liwanag',
        'Loyola', 'Mabalot', 'Macatangay', 'Madrigal', 'Magbanua', 'Malig',
        'Mangahas', 'Manlangit', 'Mariano', 'Matias', 'Nazareno', 'Olalia',
        'Ordonez', 'Orlanes', 'Palad', 'Peralejo', 'Rada', 'Ronquillo',
        'Sabado', 'Sagun', 'San Pedro', 'Sarmiento', 'Sotto', 'Tabora',
        'Talens', 'Tolentino', 'Vitug',
    ],
];
