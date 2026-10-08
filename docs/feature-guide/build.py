"""
Print docs/feature-guide/feature-guide.html to SchoolPilot-Feature-Guide.pdf
at the repository root, using headless Chrome or Edge.

    python docs/feature-guide/build.py            # build the PDF
    python docs/feature-guide/build.py --preview  # also write page PNGs next to it

Needs Chrome or Edge installed; --preview needs PyMuPDF (pip install pymupdf).
"""
import os
import pathlib
import subprocess
import sys

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent.parent
SOURCE = HERE / "feature-guide.html"
OUT = ROOT / "SchoolPilot-Feature-Guide.pdf"

BROWSERS = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "google-chrome",
    "chromium",
]


def browser() -> str:
    for candidate in BROWSERS:
        if os.path.isabs(candidate) and os.path.exists(candidate):
            return candidate
        if not os.path.isabs(candidate):
            return candidate
    sys.exit("Chrome or Edge not found.")


def main() -> None:
    subprocess.run(
        [
            browser(),
            "--headless=new",
            "--disable-gpu",
            "--no-pdf-header-footer",
            "--allow-file-access-from-files",
            f"--print-to-pdf={OUT}",
            SOURCE.as_uri(),
        ],
        check=True,
        capture_output=True,
    )
    print(f"Wrote {OUT}")

    if "--preview" in sys.argv:
        import pymupdf

        doc = pymupdf.open(OUT)
        for i, page in enumerate(doc, start=1):
            target = HERE / f"preview-page-{i}.png"
            page.get_pixmap(dpi=80).save(target)
        print(f"{len(doc)} pages; previews in {HERE}")


if __name__ == "__main__":
    main()
