const fs = require('fs');
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

let dataBuffer = fs.readFileSync(pdfPath);

pdf(dataBuffer).then(function (data) {
    // data.text contains the full text of the PDF across all pages
    process.stdout.write(data.text);
}).catch(function (error) {
    console.error("Error parsing PDF: " + error.message);
    process.exit(1);
});
