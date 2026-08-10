"""CSV/JSON z LiveTag.Pro -> raw_frame.

DO IMPLEMENTACJI: przeniesienie logiki z build_dashboard.py, bez zmiany wyniku.
Bramka odbioru: wyjście identyczne z dzisiejszym skryptem (test złoty).

Pułapki obowiązkowe (CLAUDE.md §3):
  - pole `labels` zawiera przecinki w cudzysłowach — parser musi respektować cytowanie
  - xG w polu `comment`, polski przecinek dziesiętny
  - `begin` bywa ujemny -> max(0, begin)
  - przerwa z największej luki w środkowej 1/3 meczu
"""
