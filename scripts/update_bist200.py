#!/usr/bin/env python3
import json
import os

import borsapy as bp


def resolve_bist200_symbol() -> str:
    indices = bp.all_indices(detailed=True)
    for item in indices:
        symbol = item.get("symbol", "")
        name = (item.get("name") or "").lower()
        count = int(item.get("count") or 0)
        if "bist 200" in name or symbol.upper() == "XU200" or count == 200:
            return symbol
    raise RuntimeError("BIST 200 endeksi bulunamadı.")


def main() -> None:
    symbol = resolve_bist200_symbol()
    index = bp.Index(symbol)
    components = index.components
    if not components:
        raise RuntimeError("BIST 200 bileşenleri boş döndü.")

    data = {item["symbol"]: item.get("name", "") for item in components}
    data = dict(sorted(data.items()))

    repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), os.pardir))
    data_dir = os.path.join(repo_root, "data")
    os.makedirs(data_dir, exist_ok=True)
    output_path = os.path.join(data_dir, "bist200.json")

    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

    print(f"{len(data)} hisse yazıldı: {output_path}")


if __name__ == "__main__":
    main()
