<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Academic Program Catalog (D-42)
|--------------------------------------------------------------------------
|
| The authoritative list of programs each college offers, plus the year
| levels that college uses. Keyed by college CODE (colleges.code), so the
| catalog survives a reseed that hands out different college IDs.
|
| Why a config file and not a table: the adviser needs monthly clinic
| reports grouped per program, which free text could never support — but
| there is no admin CRUD requirement for programs, and a table would push
| the PRD's canonical schema past 10 tables. So this is a fixed catalog:
| student_profiles.course keeps its name and stays a plain string, and the
| server validates that string against this file (App\Support\Programs).
|
| The UI labels the field "Program"; the column stays `course` because the
| official DHVSU form (D-25) prints "Course, Year & Section".
|
| Majors are FLAT entries — "…major in English" is its own option, not a
| second cascade level.
|
| EDITORIAL CALLS on the adviser's raw list (flagged for confirmation):
|   1. COE listed "Bachelor of Technical-Vocational Teacher Education"
|      twice — once with two majors, once bare. Recorded as three entries:
|      the two majored tracks plus one bare general track. No duplicate.
|   2. "Methods of Teaching" was indented under the BSEd majors, so it is
|      recorded as "Bachelor of Secondary Education major in Methods of
|      Teaching".
|   3. Graduate Studies entries carrying "(Academic and Professional
|      Track)" keep the base program name only — a track is not a separate
|      program. The exception is MPA major in Regulatory Management
|      Systems, kept because it names a MAJOR, not a track.
|   4. Dropped the "(New!)" marker from Master in Information Technology
|      and fixed the doubled "in in" in the CAS Biology entry.
|
| Year levels are per COLLEGE, not per program. Associate in Computer
| Technology is a 2-year program inside a 1–4 college and therefore offers
| years 1–4 here; per-program year levels are deliberately not modelled.
|
| Senior High School is absent on purpose — it is not a PSU main-campus
| unit and is removed from the college list by D-43.
|
*/

/** The ordinary undergraduate ladder, reused by most colleges. */
$undergraduate = [
    '1' => '1st Year',
    '2' => '2nd Year',
    '3' => '3rd Year',
    '4' => '4th Year',
];

/** Engineering and Architecture run a fifth year. */
$fiveYear = $undergraduate + ['5' => '5th Year'];

/** Graduate Studies runs two. */
$graduate = [
    '1' => '1st Year',
    '2' => '2nd Year',
];

/** Junior high is Grades 7–10, so its storage keys are grade numbers. */
$juniorHigh = [
    '7' => 'Grade 7',
    '8' => 'Grade 8',
    '9' => 'Grade 9',
    '10' => 'Grade 10',
];

return [

    'COE' => [
        'programs' => [
            'Bachelor of Elementary Education',
            'Bachelor of Early Childhood Education',
            'Bachelor of Secondary Education major in English',
            'Bachelor of Secondary Education major in Filipino',
            'Bachelor of Secondary Education major in Mathematics',
            'Bachelor of Secondary Education major in Social Studies',
            'Bachelor of Secondary Education major in General Science',
            'Bachelor of Secondary Education major in Methods of Teaching',
            'Bachelor of Physical Education',
            'Bachelor of Culture and Arts Education',
            'Bachelor of Technology and Livelihood Education major in Home Economics',
            'Bachelor of Technology and Livelihood Education major in Industrial Arts',
            'Bachelor of Technical-Vocational Teacher Education major in Food and Service Management',
            'Bachelor of Technical-Vocational Teacher Education major in Garments Fashion and Design',
            'Bachelor of Technical-Vocational Teacher Education',
            'Bachelor of Science in Exercises and Sports Science major in Fitness and Sports Management',
            'Bachelor of Science in Exercises and Sports Science major in Fitness and Sports Coaching',
        ],
        'year_levels' => $undergraduate,
    ],

    'CEA' => [
        'programs' => [
            'Bachelor of Science in Civil Engineering',
            'Bachelor of Science in Computer Engineering',
            'Bachelor of Science in Electrical Engineering',
            'Bachelor of Science in Electronics Engineering',
            'Bachelor of Science in Industrial Engineering',
            'Bachelor of Science in Mechanical Engineering',
            'Bachelor of Science in Architecture',
        ],
        'year_levels' => $fiveYear,
    ],

    'CBS' => [
        'programs' => [
            'Bachelor of Science in Business Administration major in Marketing Management',
            'Bachelor of Science in Business Administration major in Business Economics',
            'Bachelor of Science in Entrepreneurship',
            'Bachelor of Science in Accountancy',
            'Bachelor of Science in Accounting Info Systems',
            'Bachelor of Public Administration',
            'Bachelor of Science in Real Estate Management',
            'Bachelor of Science in Legal Management',
            'Bachelor of Science in Logistics and Supply Chain Management',
        ],
        'year_levels' => $undergraduate,
    ],

    'CAS' => [
        'programs' => [
            'Bachelor of Science in Environmental Science',
            'Bachelor of Science in Mathematics',
            'Bachelor of Science in Statistics',
            'Bachelor of Science in Biology',
            'Bachelor of Science in Nursing',
        ],
        'year_levels' => $undergraduate,
    ],

    'CSSP' => [
        'programs' => [
            'Bachelor of Science in Psychology',
            'Bachelor of Science in Human Services',
            'Bachelor of Science in Sociology',
            'Bachelor of Science in Social Work',
            'Bachelor of Science in Social Work (Second Degree - Trimester)',
        ],
        'year_levels' => $undergraduate,
    ],

    'CCS' => [
        'programs' => [
            'Associate in Computer Technology',
            'Bachelor of Science in Information Technology',
            'Bachelor of Science in Information Systems',
            'Bachelor of Science in Computer Science',
        ],
        'year_levels' => $undergraduate,
    ],

    'CHTM' => [
        'programs' => [
            'Bachelor of Science in Hospitality Management',
            'Bachelor of Science in Tourism Management',
            'Bachelor of Science in Tourism Management Major in Events Management',
        ],
        'year_levels' => $undergraduate,
    ],

    'CIT' => [
        'programs' => [
            'Bachelor of Science in Industrial Technology major in Automotive Technology',
            'Bachelor of Science in Industrial Technology major in Electronics Technology',
            'Bachelor of Science in Industrial Technology major in Electrical Technology',
            'Bachelor of Science in Industrial Technology major in Garments Fashion and Design',
            'Bachelor of Science in Industrial Technology major in Food and Service Management',
            'Bachelor of Science in Industrial Technology major in Mechatronics',
            'Bachelor of Science in Industrial Technology major in Graphics Technology',
            'Bachelor of Science in Industrial Technology major in Wellness and Beauty Care',
            'Bachelor of Science in Industrial Technology major in Instrumentation and Control',
            'Bachelor of Science in Industrial Technology major in Mechanical Technology',
            'Bachelor of Science in Industrial Technology major in Woodworking Technology',
            'Bachelor of Science in Industrial Technology major in Welding Technology',
        ],
        'year_levels' => $undergraduate,
    ],

    'LAW' => [
        'programs' => [
            'Juris Doctor (Law)',
            'Juris Doctor Bridge Program',
        ],
        'year_levels' => $undergraduate,
    ],

    'GS' => [
        'programs' => [
            'Doctor of Education major in Educational Management',
            'Doctor of Public Administration',
            'Master in Information Technology',
            'Master of Arts in Education major in Educational Management',
            'Master of Arts in Education major in English',
            'Master of Arts in Education major in Filipino',
            'Master of Arts in Education major in Mathematics',
            'Master of Arts in Education major in Physical Education',
            'Master of Arts in Education major in Social Studies',
            'Master of Arts in Education major in General Science',
            'Master of Arts in Education major in Technology and Livelihood Education',
            'Master of Public Administration',
            'Master of Public Administration major in Regulatory Management Systems',
            'Master of Business Administration',
            'Master of Science in Social Work',
            'Master of Engineering Management',
            'Master of Arts in Guidance and Counseling',
        ],
        'year_levels' => $graduate,
    ],

    'LHS' => [
        'programs' => [
            'Laboratory High School (Grade 7 to 10)',
        ],
        'year_levels' => $juniorHigh,
    ],

];
