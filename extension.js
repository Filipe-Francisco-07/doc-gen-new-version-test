const vscode = require('vscode');
const fs = require('fs');
const path = require('path');
const cp = require('child_process');

const SECRET_KEY = 'docgen.openai_api_key';
const oc = vscode.window.createOutputChannel('DocGen');

/* ===========================
CONFIG
=========================== */

function getConfig() {
  const cfg = vscode.workspace.getConfiguration('phpDocgen');

  return {
    phpPath: cfg.get('phpPath', 'php'),
    env: {
      OPENAI_MODEL: cfg.get('env.OPENAI_MODEL', 'gpt-4o-mini'),
      OPENAI_BASE: cfg.get('env.OPENAI_BASE', '')
    }
  };
}

/* ===========================
API KEY
=========================== */

async function getApiKey(context) {
  return await context.secrets.get(SECRET_KEY);
}

async function setApiKey(context) {

  const key = await vscode.window.showInputBox({
    prompt: 'Informe sua OpenAI API Key',
    password: true
  });

  if (!key) return;

  await context.secrets.store(SECRET_KEY, key);

  vscode.window.showInformationMessage('OpenAI API Key salva.');
}

async function clearApiKey(context) {

  await context.secrets.delete(SECRET_KEY);

  vscode.window.showInformationMessage('OpenAI API Key removida.');
}

/* ===========================
UTIL
=========================== */

function normalizeIndent(text) {

  const lines = text.split('\n');

  const indents = lines
    .filter(l => l.trim() !== '')
    .map(l => l.match(/^(\s*)/)[1].length);

  const minIndent = Math.min(...indents, 0);

  if (!minIndent) return text;

  return lines.map(l => l.slice(minIndent)).join('\n');
}

function balanceBraces(code) {

  const open = (code.match(/{/g) || []).length;
  const close = (code.match(/}/g) || []).length;

  let diff = open - close;

  while (diff > 0) {
    code += "\n}";
    diff--;
  }

  return code;
}

function buildSafeFragment(fragment) {

  fragment = normalizeIndent(fragment);
  fragment = balanceBraces(fragment);

  const hasClass = /^\s*class\s+/m.test(fragment);
  const hasFunction = /\bfunction\b/.test(fragment);

  if (hasClass) {

    return {
      code: `<?php\n${fragment}\n`,
      offset: 1
    };

  }

  if (hasFunction) {

    return {
      code: `<?php
class __DocGenTemp {

${fragment}

}
`,
      offset: 3
    };

  }

  return {
    code: `<?php
function __docgen_temp() {

${fragment}

}
`,
    offset: 3
  };
}

function applyIndent(block, indent) {

  return block
    .split('\n')
    .map(l => indent + l)
    .join('\n');
}

/* ===========================
RUN PHP DOCGEN
=========================== */

async function runPhp(context, editor) {

  oc.clear();
  oc.appendLine('[DocGen] Iniciando documentação...');
  oc.show(true);

  const { phpPath, env } = getConfig();

  const extensionRoot = context.extensionPath;

  const script = path.join(extensionRoot, 'engine', 'bin', 'run.php');
  const inputDir = path.join(extensionRoot, 'engine', 'input');
  const outputDir = path.join(extensionRoot, 'engine', 'output');

  const inputFile = path.join(inputDir, 'entrada.php');

  await fs.promises.mkdir(inputDir, { recursive: true });
  await fs.promises.mkdir(outputDir, { recursive: true });

  const doc = editor.document;
  const sel = editor.selection;

  if (sel.isEmpty) {
    vscode.window.showInformationMessage('Selecione um trecho.');
    return;
  }

  const selected = doc.getText(sel);

  const wrapped = buildSafeFragment(selected);

  await fs.promises.writeFile(inputFile, wrapped.code, 'utf8');

  oc.appendLine("----- PHP enviado ao engine -----");
  oc.appendLine(wrapped.code);
  oc.appendLine("---------------------------------");

  const args = [
    script,
    '--input', inputFile,
    '--base', 'entrada'
  ];

  const apiKey = await getApiKey(context);

  const childEnv = { ...process.env };

  if (apiKey) childEnv.OPENAI_API_KEY = apiKey;
  if (env.OPENAI_MODEL) childEnv.OPENAI_MODEL = env.OPENAI_MODEL;
  if (env.OPENAI_BASE) childEnv.OPENAI_BASE = env.OPENAI_BASE;

  let result;

  try {

    result = cp.spawnSync(phpPath, args, {
      cwd: path.join(extensionRoot, 'engine'),
      env: childEnv,
      encoding: 'utf8'
    });

  } catch (err) {

    vscode.window.showErrorMessage('Erro ao executar PHP.');
    return;

  }

  if (result.stdout) oc.appendLine(result.stdout);
  if (result.stderr) oc.appendLine(result.stderr);

  const mapFile = path.join(outputDir, 'doc_map_entrada.json');
  const docsFile = path.join(outputDir, 'generated_docs_entrada.json');

  if (!fs.existsSync(mapFile) || !fs.existsSync(docsFile)) {

    vscode.window.showWarningMessage('DocGen: arquivos de saída não encontrados.');
    return;
  }

  const map = JSON.parse(await fs.promises.readFile(mapFile, 'utf8'));
  const docs = JSON.parse(await fs.promises.readFile(docsFile, 'utf8'));

  const inserts = [];

  for (const item of map) {

    if (item.fqn && item.fqn.includes('__DocGenTemp')) continue;

    const docBlock = docs[item.id];
    if (!docBlock) continue;

    const originalLine = sel.start.line + (item.line - wrapped.offset);
    const insertLine = Math.max(originalLine - 1, 0);

    const lineText = doc.lineAt(originalLine).text;
    const indent = (lineText.match(/^(\s*)/) || [''])[0];

    inserts.push({
      line: insertLine,
      doc: applyIndent(docBlock, indent)
    });
  }

  inserts.sort((a, b) => b.line - a.line);

  await editor.edit(edit => {

    for (const ins of inserts) {

      edit.insert(
        new vscode.Position(ins.line, 0),
        ins.doc + '\n'
      );

    }

  });

  vscode.window.setStatusBarMessage(
    'DocGen: documentação aplicada.',
    3000
  );
}

/* ===========================
EXTENSION
=========================== */

function activate(context) {

  context.subscriptions.push(

    vscode.commands.registerCommand('docgen.documentSelection', async () => {

      const editor = vscode.window.activeTextEditor;
      if (!editor) return;

      await runPhp(context, editor);

    }),

    vscode.commands.registerCommand('docgen.setApiKey', () => setApiKey(context)),
    vscode.commands.registerCommand('docgen.clearApiKey', () => clearApiKey(context))

  );
}

function deactivate() {}

module.exports = { activate, deactivate };