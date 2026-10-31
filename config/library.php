<?php
return [
 'version' => '0.2.1',
 'seed_units' => [...array_map(fn ($n) => sprintf('Unit %02d', $n), range(1, 20)), 'Non Cadre (Exam)', 'Cadre (Exam)', 'Non Cadre (Confidential)', 'Cadre (Confidential)', 'IT Section', 'Administration Wing', 'Law Wing'],
];
