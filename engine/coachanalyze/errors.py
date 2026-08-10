"""Kody wyjścia zgodne z docs/KONTRAKT_CLI.md. Zmiana tutaj = zmiana po stronie PHP."""


class EngineError(Exception):
    exit_code = 4
    code = "INTERNAL"


class NotLiveTagExport(EngineError):
    """Plik nie wygląda na eksport LiveTag.Pro."""
    exit_code = 2
    code = "NOT_LIVETAG"


class MissingColumns(EngineError):
    """Brak kolumn wymaganych do przetworzenia."""
    exit_code = 3
    code = "MISSING_COLUMNS"

    def __init__(self, columns):
        self.columns = list(columns)
        super().__init__("Brak wymaganych kolumn: " + ", ".join(self.columns))


class EngineTimeout(EngineError):
    exit_code = 5
    code = "TIMEOUT"
