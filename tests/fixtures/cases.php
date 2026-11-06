<?php
// G3 regression cases (bin/check_m1.php): the inputs that fill a type in the form, and the hand-filled kit
// template (tests/fixtures/<fixture>.tex) it must match, byte for byte. M = Παπαδόπουλος Γιώργος, F = Παπαδοπούλου Μαρία.
$M = ['name' => 'Παπαδόπουλος Γιώργος', 'age' => '45', 'gender' => 'M'];
$F = ['name' => 'Παπαδοπούλου Μαρία', 'age' => '45', 'gender' => 'F'];
$sd = ['surgery_date' => '2026-01-10'];
return [
    ['type' => 'vevaiosi-mri', 'fixture' => 'mri-handfilled-M', 'patient' => $M, 'fields' => $sd],
    ['type' => 'vevaiosi-mri', 'fixture' => 'mri-handfilled-F', 'patient' => $F, 'fields' => $sd, 'groups' => ['purpose' => 'purpose_work']],
    ['type' => 'vevaiosi-ylikon', 'fixture' => 'ylikon-M', 'patient' => $M, 'fields' => $sd + [
        'procedure' => 'αναθεώρηση της πρόσθιας αυχενικής δισκεκτομής ACDF στο επίπεδο Α3/4',
        'indication' => 'λόγω ατυχηματικής ρήξης μήνιγγας στο προηγούμενο χειρουργείο και διαρροής εγκεφαλονωτιαίου υγρού.'],
        'extra' => ['materials' => [['text' => "Σύστημα πλακών, Εταιρεία Α, 1 τεμ.,\nΚλειστό σύστημα παροχέτευσης, Εταιρεία Β, 1 τεμ."]]]],
    ['type' => 'adeia', 'fixture' => 'adeia-M', 'patient' => $M, 'fields' => [
        'visit_reason' => 'αυχεναλγίας με ριζιτική συνδρομή κυρίως αριστερά',
        'findings' => 'Από τον έλεγχο με Μαγνητική Τομογραφία Αυχενικής Μοίρας της Σπονδυλικής Στήλης διαπιστώθηκε εκφυλιστική δισκοπάθεια Α5/6 και Α6/7.',
        'followup' => '15 ημέρες']],
    ['type' => 'adeia', 'fixture' => 'adeia-F', 'patient' => $F, 'closing' => 'Ο ιατρός',
        'blocks' => ['rest' => ['on' => false], 'sick_leave' => ['on' => true], 'return' => ['on' => true], 'avoid_cold' => ['on' => true], 'followup_study' => ['on' => true]],
        'sections' => ['assessment' => ['on' => true]], 'groups' => ['purpose' => 'purpose_fund'], 'fields' => [
        'leave_days' => 'δύο (2) εβδομάδες', 'return_date' => '2026-02-01', 'followup_study' => 'ΟΜΣΣ',
        'visit_reason' => 'αυχεναλγίας με ριζιτική συνδρομή κυρίως αριστερά',
        'findings' => 'Από τον έλεγχο με Μαγνητική Τομογραφία Αυχενικής Μοίρας της Σπονδυλικής Στήλης διαπιστώθηκε εκφυλιστική δισκοπάθεια Α5/6 και Α6/7.',
        'followup' => '15 ημέρες', 'assessment' => 'Μυϊκή ισχύς 3/5, θεραπευτικό πρόγραμμα και αναμενόμενη βελτίωση.']],
    ['type' => 'vevaiosi-amoivis', 'fixture' => 'amoivis-M', 'patient' => $M, 'fields' => $sd + [
        'clinic_name' => 'Ιατρικό Περιστερίου', 'procedure' => 'μικροδισκεκτομή Ο4-Ο5', 'amount' => '500', 'fee_role' => 'βοηθού χειρουργού',
        'apy_number' => '108', 'apy_date' => '2026-01-12']],
    ['type' => 'gnomateysi-exetaseon', 'fixture' => 'gnomateysi-M', 'patient' => $M, 'fields' => [
        'diagnosis' => 'εκφυλιστική δισκοπάθεια με ριζιτική συμμετοχή της Αυχενικής Μοίρας της Σπονδυλικής Στήλης',
        'findings' => 'Ευρήματα από την κλινική εξέταση και τον απεικονιστικό έλεγχο.',
        'exam' => 'Μαγνητική Τομογραφία ΑΜΣΣ προ και μετά τη χορήγηση σκιαγραφικής ουσίας']],
    ['type' => 'gnomateysi-exetaseon', 'fixture' => 'gnomateysi-variant', 'patient' => $M, 'closing' => 'Ο ιατρός',
        'blocks' => ['visit' => ['on' => true], 'mri' => ['on' => false], 'mri_postop' => ['on' => true], 'pt' => ['on' => true]], 'groups' => ['purpose' => 'purpose_any'], 'fields' => [
        'diagnosis' => 'εκφυλιστική δισκοπάθεια με ριζιτική συμμετοχή της Αυχενικής Μοίρας της Σπονδυλικής Στήλης',
        'visit_reason' => 'παρετικής οσφυοϊσχιαλγίας δεξιά, με αδυναμία τετρακεφάλου',
        'findings' => 'Ευρήματα από την κλινική εξέταση και τον απεικονιστικό έλεγχο.',
        'exam_postop' => 'Μαγνητικής Τομογραφίας ΑΜΣΣ και ΘΜΣΣ', 'sessions' => '10 (δέκα)', 'pt_goal' => 'ενδυνάμωση των κάτω άκρων']],
    ['type' => 'syntagi', 'fixture' => 'syntagi-A4', 'patient' => $M, 'meds' => ['medications' => [
        ['name' => 'tb Paracetamol', 'dose' => '1000mg', 'freq' => '1 x 3', 'days' => '10', 'notes' => ''],
        ['name' => 'tb Medrol', 'dose' => '4mg', 'freq' => '1 x 3{br}1 x 2{br}1 x 1{br}STOP', 'days' => '', 'notes' => 'για 5 ημέρες{br}για 5 ημέρες{br}για 5 ημέρες'],
        ['name' => 'tb Zinadol', 'dose' => '500mg', 'freq' => '1 x 2', 'days' => '5', 'notes' => 'αντιβίωση'],
        ['name' => 'caps Omeprazole', 'dose' => '40mg', 'freq' => '1 x 2', 'days' => '10', 'notes' => 'προ φαγητού'],
        ['name' => 'tb Tegretol', 'dose' => '200mg', 'freq' => '1 x 3', 'days' => '', 'notes' => 'με τροφή, σταθερές ώρες. **Μην διακόπτεται απότομα**']]]],
    ['type' => 'syntagi-a5', 'fixture' => 'syntagi-A5', 'patient' => array_merge($F, ['age' => '52']), 'blocks' => ['diagnosis_row' => ['on' => true], 'allergies_row' => ['on' => true], 'surgery_row' => ['on' => true], 'note_no_reduce' => ['on' => true]],
        'fields' => $sd + ['diagnosis' => 'οσφυοϊσχιαλγία', 'allergies' => 'καμία γνωστή', 'drug_names' => 'Tegretol / Lyrica'],
        'meds' => ['medications' => [
        ['name' => 'tb Paracetamol', 'dose' => '1000mg', 'freq' => '1 x 3', 'days' => '10', 'notes' => ''],
        ['name' => 'tb Zinadol', 'dose' => '500mg', 'freq' => '1 x 2', 'days' => '5', 'notes' => 'αντιβίωση'],
        ['name' => 'caps Omeprazole', 'dose' => '40mg', 'freq' => '1 x 2', 'days' => '10', 'notes' => 'προ φαγητού']]]],
];
