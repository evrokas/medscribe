<?php
// Unit test of the pure part of bin/seed_archive.php. Run by tests/run_all.php.
define('SEED_TEST', 1);
require_once __DIR__ . '/../../web/render.php';
require_once __DIR__ . '/../../web/pool.php';
// load only the pure function (the file's main part needs the database)
$src = file_get_contents(__DIR__ . '/../../bin/seed_archive.php');
preg_match('/function seed_letter_texts.*?\n}\n/s', $src, $m);
eval($m[0]);
$fail = 0; $n = 0;
function eq($got, $want, string $label): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL  $label\n  got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; } }
$letter = <<<'TEX'
\documentclass{article}
\providecommand\PatientName{Ιωάννου Γεώργιος}
\providecommand\AMKA{12345678901}
\begin{document}
\begin{PatientInfo}
  \Info{Διάγνωση}{κάτι}
\end{PatientInfo}
\section*{Φροντίδα Τραύματος}
Ο ασθενής Ιωάννου Γεώργιος παρέμεινε στην κλινική για τρεις ημέρες.
% a comment
\begin{itemize}
  \item Το τραύμα πρέπει να παραμένει \textbf{καθαρό} και στεγνό.
  \item Κοντό.
  \item Με εντολή $x$ και \foo{bar} εδώ υπάρχει LaTeX.
\end{itemize}
Το ΑΜΚΑ 12345678901 καταγράφηκε σε αυτό το γράμμα.
\end{document}
TEX;
eq(seed_letter_texts($letter), [
    ['archive.φροντίδα_τραύματος', 'Ο ασθενής @patient παρέμεινε στην κλινική για τρεις ημέρες.'],
    ['archive.φροντίδα_τραύματος', 'Το τραύμα πρέπει να παραμένει **καθαρό** και στεγνό.'],
    ['archive.φροντίδα_τραύματος', 'Το ΑΜΚΑ @amka καταγράφηκε σε αυτό το γράμμα.']], 'plain text is kept with patient details as tokens; short lines, comments, patient rows and LaTeX are skipped');
eq(seed_letter_texts('\\providecommand\\PatientName{Επώνυμο Όνομα}\\begin{document}x\\end{document}'), [], 'a letter with the template placeholder name is skipped');
eq(seed_letter_texts('no patient here'), [], 'a file without a patient name is skipped');
echo $fail ? "$fail of $n seed tests FAILED\n" : '';
exit($fail ? 1 : 0);
