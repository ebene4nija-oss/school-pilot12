import os
import shutil
import fitz # PyMuPDF

pdf_src = os.path.abspath("SchoolPilot_Platform_Features.pdf")
artifact_dir = r"C:\Users\Administrator\.gemini\antigravity\brain\d74b51c2-4a18-4552-81e7-c72db97b99f4"
pdf_dest = os.path.join(artifact_dir, "SchoolPilot_Platform_Features.pdf")

shutil.copy(pdf_src, pdf_dest)
print(f"Copied PDF to artifact dir: {pdf_dest}")

doc = fitz.open(pdf_src)
print(f"Total PDF pages: {len(doc)}")

for i, page in enumerate(doc):
    pix = page.get_pixmap(dpi=150)
    img_path = os.path.join(artifact_dir, f"page_{i+1}.png")
    pix.save(img_path)
    print(f"Saved page {i+1} as image: {img_path}")
