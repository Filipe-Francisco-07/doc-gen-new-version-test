const vscode = require("vscode");
const fs = require("fs");
const path = require("path");
const cp = require("child_process");

const SECRET_KEY = "docgen.openai_api_key";
const oc = vscode.window.createOutputChannel("DocGen");

/* ======================
CONFIG
====================== */

function getConfig() {
  const cfg = vscode.workspace.getConfiguration("phpDocgen");

  return {
    phpPath: cfg.get("phpPath", "php"),
    env: {
      OPENAI_MODEL: cfg.get("env.OPENAI_MODEL", "gpt-4o-mini"),
      OPENAI_BASE: cfg.get("env.OPENAI_BASE", "")
    }
  };
}

/* ======================
API KEY
====================== */

async function getApiKey(context) {
  return context.secrets.get(SECRET_KEY);
}

async function setApiKey(context) {
  const key = await vscode.window.showInputBox({
    prompt: "Informe sua OpenAI API Key",
    password: true
  });

  if (!key) return;

  await context.secrets.store(SECRET_KEY, key);
  vscode.window.showInformationMessage("OpenAI API Key salva.");
}

async function clearApiKey(context) {
  await context.secrets.delete(SECRET_KEY);
  vscode.window.showInformationMessage("OpenAI API Key removida.");
}

/* ======================
UTILS
====================== */

function normalizeIndent(text) {
  const lines = text.split("\n");

  const indents = lines
    .filter(l => l.trim())
    .map(l => l.match(/^(\s*)/)[1].length);

  const min = Math.min(...indents, 0);
  if (!min) return text;

  return lines.map(l => l.slice(min)).join("\n");
}

function balanceBraces(code) {
  const open = (code.match(/{/g) || []).length;
  const close = (code.match(/}/g) || []).length;

  let diff = open - close;

  while (diff-- > 0) code += "\n}";

  return code;
}

function sanitizeFragment(fragment) {

  fragment = normalizeIndent(fragment);

  const lines = fragment.split("\n");

  while (lines.length && !lines[0].trim()) {
    lines.shift();
  }

  fragment = lines.join("\n");

  if (/^\s*<\?php/.test(fragment)) {
    return {
      code: fragment,
      offset: 0
    };
  }

  const open = (fragment.match(/{/g) || []).length;
  const close = (fragment.match(/}/g) || []).length;

  if (open > close) {
    fragment += "\n" + "}".repeat(open - close);
  }

  const hasClass = /^\s*(class|interface|trait|enum)\s+/m.test(fragment);
  const hasFunction = /\bfunction\b/.test(fragment);
  const hasVisibility = /^\s*(public|protected|private)\s+/m.test(fragment);

  if (hasClass) {
    return {
      code: `<?php\n${fragment}\n`,
      offset: 1
    };
  }

  if (hasVisibility || hasFunction) {
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
function __docgen_fragment() {

${fragment}

}
`,
    offset: 3
  };
}

function applyIndent(text, indent) {
  return text.split("\n").map(l => indent + l).join("\n");
}

function expandSelection(editor) {

  const doc = editor.document;

  let start = editor.selection.start.line;
  let end = editor.selection.end.line;

  while (start > 0 && !doc.lineAt(start).text.includes("function")) {
    start--;
  }

  while (end < doc.lineCount && !doc.lineAt(end).text.includes("}")) {
    end++;
  }

  return doc.getText(
    new vscode.Range(start, 0, end, 0)
  );
}

/* ======================
RUN ENGINE
====================== */

async function runPhp(context, editor) {

  oc.clear();
  oc.appendLine("[DocGen] Iniciando documentação...");
  oc.show(true);

  const { phpPath, env } = getConfig();

  const root = context.extensionPath;
  const engineDir = path.join(root, "engine");

  const script = path.join(engineDir, "bin", "run.php");
  const inputDir = path.join(engineDir, "input");
  const outputDir = path.join(engineDir, "output");

  const inputFile = path.join(inputDir, "entrada.php");

  await fs.promises.mkdir(inputDir, { recursive: true });
  await fs.promises.mkdir(outputDir, { recursive: true });

  const selection = editor.selection;

  if (selection.isEmpty) {
    vscode.window.showInformationMessage("Selecione um trecho.");
    return;
  }

  const selected = expandSelection(editor);
  const wrapped = sanitizeFragment(selected);

  await fs.promises.writeFile(inputFile, wrapped.code, "utf8");

  oc.appendLine("----- PHP enviado ao engine -----");
  oc.appendLine(wrapped.code);
  oc.appendLine("---------------------------------");

  const apiKey = await getApiKey(context);

  const childEnv = {
    ...process.env,
    OPENAI_API_KEY: apiKey || "",
    OPENAI_MODEL: env.OPENAI_MODEL,
    OPENAI_BASE: env.OPENAI_BASE
  };

  const result = cp.spawnSync(
    phpPath,
    [script, "--input", inputFile, "--base", "entrada"],
    {
      cwd: engineDir,
      env: childEnv,
      encoding: "utf8"
    }
  );

  if (result.stdout) oc.appendLine(result.stdout);
  if (result.stderr) oc.appendLine(result.stderr);

  const mapFile = path.join(outputDir, "doc_map_entrada.json");
  const docsFile = path.join(outputDir, "generated_docs_entrada.json");

  if (!fs.existsSync(mapFile) || !fs.existsSync(docsFile)) {
    vscode.window.showWarningMessage("DocGen: arquivos de saída não encontrados.");
    return;
  }

  const map = JSON.parse(await fs.promises.readFile(mapFile, "utf8"));
  const docs = JSON.parse(await fs.promises.readFile(docsFile, "utf8"));

  const inserts = [];

  for (const item of map) {

    if (item.type === "class" && item.name === "__DocGenTemp") continue;

    const docBlock = docs[item.id];
    if (!docBlock) continue;

    const originalLine = selection.start.line + (item.line - wrapped.offset);
    const insertLine = Math.max(originalLine - 1, 0);

    const lineText = editor.document.lineAt(originalLine).text;
    const indent = lineText.match(/^(\s*)/)?.[0] ?? "";

    inserts.push({
      line: insertLine,
      doc: applyIndent(docBlock, indent)
    });
  }

  inserts.sort((a, b) => b.line - a.line);

  await editor.edit(edit => {
    for (const ins of inserts) {
      edit.insert(new vscode.Position(ins.line, 0), ins.doc + "\n");
    }
  });

  vscode.window.setStatusBarMessage("DocGen: documentação aplicada.", 3000);
}

const { exec } = require("child_process");

/* ======================
GIT
====================== */

function run(cmd, cwd) {
  return new Promise((resolve, reject) => {

    exec(cmd, { cwd }, (err, stdout, stderr) => {

      if (err) {
        reject(stderr || err.message);
        return;
      }

      resolve(stdout);

    });

  });
}

async function pushToGit(context) {

  const workspace = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;

  if (!workspace) {
    vscode.window.showErrorMessage("Nenhum projeto aberto.");
    return;
  }

  const cfg = vscode.workspace.getConfiguration("phpDocgen");
  const autoDoc = cfg.get("autoDocumentOnCommit", true);

  if (autoDoc) {

    const editor = vscode.window.activeTextEditor;

    if (editor) {

      const changed = await autoDocumentFile(context, editor);

      if (changed) {
        await new Promise(r => setTimeout(r, 500));
      }

    }

  }

  try {

    await run("git add .", workspace);

    const message = await vscode.window.showInputBox({
      prompt: "Mensagem do commit",
      value: "DocGen update"
    });

    await run(`git commit -m "${message}"`, workspace);

    await run("git push", workspace);

    vscode.window.showInformationMessage("Projeto enviado para o GitHub.");

  } catch (err) {

    vscode.window.showErrorMessage("Erro ao enviar para Git: " + err);

  }
}

async function configurarGit() {

  const name = await vscode.window.showInputBox({
    prompt: "Nome do usuário Git"
  });

  const email = await vscode.window.showInputBox({
    prompt: "Email do Git"
  });

  if (!name || !email) return;

  const workspace = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;

  try {

    await run(`git config user.name "${name}"`, workspace);
    await run(`git config user.email "${email}"`, workspace);

    vscode.window.showInformationMessage("Git configurado.");

  } catch (err) {

    vscode.window.showErrorMessage("Erro ao configurar Git.");

  }
}

/* ======================
EXTENSION
====================== */

function activate(context) {

  context.subscriptions.push(

    vscode.commands.registerCommand("docgen.documentSelection", async () => {

      const editor = vscode.window.activeTextEditor;
      if (!editor) return;

      await runPhp(context, editor);

    }),

    vscode.commands.registerCommand("docgen.documentFile", async () => {

      const editor = vscode.window.activeTextEditor;
      if (!editor) return;

      const doc = editor.document;

      editor.selection = new vscode.Selection(
        new vscode.Position(0, 0),
        new vscode.Position(doc.lineCount, 0)
      );

      await runPhp(context, editor);

    }),

    vscode.commands.registerCommand("docgen.setApiKey", () => setApiKey(context)),
    vscode.commands.registerCommand("docgen.clearApiKey", () => clearApiKey(context)),
    vscode.commands.registerCommand(
      "docgen.pushToGit",
      () => pushToGit(context)
    ),

    vscode.commands.registerCommand(
      "docgen.configurarGit",
      configurarGit
    ),
  );
}

function findUndocumentedMethods(text) {

  const lines = text.split("\n");
  const methods = [];

  for (let i = 0; i < lines.length; i++) {

    const line = lines[i];

    if (/function\s+[a-zA-Z0-9_]+\s*\(/.test(line)) {

      let j = i - 1;

      while (j >= 0 && lines[j].trim() === "") {
        j--;
      }

      if (j < 0 || !lines[j].trim().startsWith("/**")) {
        methods.push(i);
      }

    }

  }

  return methods;
}

async function autoDocumentFile(context, editor) {

  const doc = editor.document;
  const text = doc.getText();

  const methods = findUndocumentedMethods(text);

  if (methods.length === 0) {
    return false;
  }

  const start = new vscode.Position(0, 0);
  const end = new vscode.Position(doc.lineCount, 0);

  editor.selection = new vscode.Selection(start, end);

  await runPhp(context, editor);

  await editor.document.save();

  return true;
}

function deactivate() {}

module.exports = { activate, deactivate };