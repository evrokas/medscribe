<?php

/**
 * Printing aids made from a document's PDF (not archived in DocArc): two A5 pages on one landscape A4 sheet
 * (the kit's tpl-a5-2up.tex / scripts/a5-2up.sh, with pdfpages). Same sandbox as every other job.
 */

/**
 * @param string $a5pdf  the A5 document
 * @param string $mode   'seq' pages in order, 1+2 on the first sheet; 'dup' every page twice on its sheet
 * @param bool   $cut    a thin dashed cut line down the middle
 * @return array compile_job()'s result (pdf = the A4 sheets)
 */
function print_two_up(string $a5pdf, string $mode = 'seq', bool $cut = true, ?int $documentId = null, string $user = 'system'): array {
    $mode = $mode === 'dup' ? 'dup' : 'seq';
    $tex = <<<'TEX'
\documentclass{article}
\usepackage[a4paper,landscape,margin=0pt]{geometry}
\usepackage{ifthen}
\usepackage{pdfpages}
\usepackage{tikz}
\providecommand\InputPDF{a5.pdf}
TEX;
    $tex .= "\n\\providecommand\\TwoUpMode{" . $mode . "}\n\\providecommand\\TwoUpCutLine{" . ($cut ? 'yes' : 'no') . "}\n";
    $tex .= <<<'TEX'
\newcount\twoupn \newcount\twoupi
\twoupn=\XeTeXpdfpagecount "\InputPDF"\relax
\def\twouppages{-}
\ifthenelse{\equal{\TwoUpMode}{dup}}{%
  \def\twouppages{}\twoupi=1
  \loop
    \ifnum\twoupi>\twoupn\else
      \edef\twouppages{\twouppages\ifx\twouppages\empty\else,\fi\the\twoupi,\the\twoupi}%
      \advance\twoupi by 1
  \repeat}{}
\newcommand\twoupcut{%
  \begin{tikzpicture}[remember picture,overlay]
    \draw[dash pattern=on 3pt off 3pt,gray,line width=0.3pt] (current page.north) -- (current page.south);
  \end{tikzpicture}}
\begin{document}
\ifthenelse{\equal{\TwoUpCutLine}{yes}}
  {\includepdf[nup=2x1,pages={\twouppages},pagecommand={\twoupcut}]{\InputPDF}}
  {\includepdf[nup=2x1,pages={\twouppages}]{\InputPDF}}
\end{document}
TEX;
    $r = compile_job([], ['a5.pdf' => $a5pdf, 'twoup.tex' => $tex . "\n"], 'twoup.tex', 1767225600, 'print', $user, $documentId);
    return $r;
}
