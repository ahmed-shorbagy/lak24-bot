const fs = require('fs');

// Polyfill for process.getBuiltinModule (sometimes missing in custom node bins)
if (typeof process.getBuiltinModule !== 'function') {
    process.getBuiltinModule = function (id) { return require(id); };
}

const pdf = require('pdf-parse');

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

        // Extract text
        const result = await pdf(dataBuffer);

        const output = {
            text: result.text,
            pageCount: result.numpages
        };

        process.stdout.write(JSON.stringify(output));
    } catch (error) {
        console.error("Error parsing PDF: " + error.message);
        process.exit(1);
    }
}

run();
