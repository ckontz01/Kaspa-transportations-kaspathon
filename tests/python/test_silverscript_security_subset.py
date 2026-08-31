import re
from pathlib import Path


SOURCE = (Path(__file__).parents[2] / "contracts" / "ride-escrow.sil").read_text(
    encoding="utf-8"
)


def test_contract_avoids_features_in_current_critical_compiler_advisory() -> None:
    stripped = re.sub(r"//.*", "", SOURCE)
    forbidden_patterns = {
        "loops": r"\bfor\s*\(",
        "ternary expressions": r"\?[^:]+:",
        "user structs": r"\bstruct\s+",
        "split operations": r"\.split\s*\(",
        "boolean arrays": r"\bbool\s*\[",
        "unsafe explicit signature casts": r"\b(?:sig|datasig)\s*\(",
        "user override of covenant state validation": r"\bfunction\s+validateOutputState\b",
        "legacy covenant decorators": r"#\[\s*covenant",
    }
    for label, pattern in forbidden_patterns.items():
        assert re.search(pattern, stripped) is None, label


def test_contract_has_no_constant_or_constructor_name_collision() -> None:
    assert " constant " not in f" {SOURCE} "
