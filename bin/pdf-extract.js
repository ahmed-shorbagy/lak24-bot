const fs = require('fs');
const { PDFParse } = require('pdf-parse');

const pdfPath = process.argv[2];

if (!pdfPath) {
    console.error("Usage: node pdf-extract.js <path-to-pdf>");
    process.exit(1);
}

if (!fs.existsSync(pdfPath)) {
    console.error("File not found: " + pdfPath);
    process.exit(1);
}

async function run() {
    try {
        const dataBuffer = fs.readFileSync(pdfPath);
        const parser = new PDFParse({ data: dataBuffer });

        // Extract text with custom page joiner for clear markers
        const result = await parser.getText({
            pageJoiner: '\n--- PAGE page_number of total_number ---\n'
        });

        const output = {
            text: result.text,
            pageCount: result.total
        };

        await parser.destroy();
        process.stdout.write(JSON.stringify(output));
    } catch (error) {
        console.error("Error parsing PDF: " + error.message);
        process.exit(1);
    }
}

run();
