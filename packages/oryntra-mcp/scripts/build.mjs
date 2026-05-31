import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import ts from 'typescript';

const packageRoot = resolve(import.meta.dirname, '..');
const sourceRoot = join(packageRoot, 'src');
const distRoot = join(packageRoot, 'dist');

/** When set, parse diagnostics only — avoids a slow full-program tsc. */
const emitOutputs = !process.argv.includes('--no-emit');

const sourceFiles = ['config.ts', 'api-client.ts', 'guides.ts', 'server.ts', 'index.ts'];

const formatHost = {
    getCurrentDirectory: () => packageRoot,
    getCanonicalFileName: (f) => f,
    getNewLine: () => '\n',
};

if (emitOutputs) {
    await rm(distRoot, { recursive: true, force: true });
}

for (const file of sourceFiles) {
    const sourcePath = join(sourceRoot, file);
    const outputPath = join(distRoot, file.replace(/\.ts$/, '.js'));
    const sourceMapPath = `${outputPath}.map`;
    const sourceCode = await readFile(sourcePath, 'utf8');

    const transpiled = ts.transpileModule(sourceCode, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
            moduleResolution: ts.ModuleResolutionKind.Bundler,
            sourceMap: true,
            strict: true,
            esModuleInterop: true,
            verbatimModuleSyntax: true,
        },
        fileName: sourcePath,
        reportDiagnostics: true,
    });

    const diagnostics = transpiled.diagnostics ?? [];

    if (diagnostics.length > 0) {
        console.error(ts.formatDiagnosticsWithColorAndContext(diagnostics, formatHost));
        process.exit(1);
    }

    if (emitOutputs) {
        await mkdir(dirname(outputPath), { recursive: true });
        await writeFile(outputPath, transpiled.outputText, 'utf8');

        if (transpiled.sourceMapText !== undefined) {
            await writeFile(sourceMapPath, rewriteSourceMap(transpiled.sourceMapText, sourcePath), 'utf8');
        }
    }
}

if (emitOutputs) {
    console.log(`Built ${sourceFiles.length} files into ${relative(packageRoot, distRoot) || 'dist'}.`);
} else {
    console.log(`Typecheck OK (${sourceFiles.length} files).`);
}

function rewriteSourceMap(sourceMapText, sourcePath) {
    const sourceMap = JSON.parse(sourceMapText);
    sourceMap.sources = [relative(distRoot, sourcePath)];

    return JSON.stringify(sourceMap);
}
