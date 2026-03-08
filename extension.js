const vscode = require("vscode");
const fs = require("fs");
const path = require("path");
const cp = require("child_process");
const https = require("https");
const sodium = require("libsodium-wrappers");


const SECRET_KEY = "docgen.openai_api_key";
const oc = vscode.window.createOutputChannel("DocGen");


/**
 * Extrai owner/repo da URL remota do git
 */
async function getRepoInfo(workspace) {
  const out = await run("git config --get remote.origin.url", workspace);
  const url = out.trim();

  // suporta https ou ssh
  const m = url.match(/github\.com[:\/]([^\/]+)\/(.+?)(\.git)?$/);
  if (!m) throw new Error("Não foi possível detectar owner/repo da URL do GitHub.");

  return { owner: m[1], repo: m[2] };
}

async function setGithubToken(context) {

  const token = await vscode.window.showInputBox({
    prompt: "Informe seu GitHub Personal Access Token (repo + actions)",
    password: true
  });

  if (!token) return;

  await context.secrets.store("docgen.github_token", token);

  vscode.window.showInformationMessage("GitHub token salvo.");
}

/**
 * Faz request HTTP simples
 */
function ghRequest(method, path, token, body) {
  const data = body ? JSON.stringify(body) : null;

  const options = {
    hostname: "api.github.com",
    path,
    method,
    headers: {
      "User-Agent": "docgen-extension",
      "Authorization": `Bearer ${token}`,
      "Accept": "application/vnd.github+json",
      "Content-Type": "application/json",
      "Content-Length": data ? Buffer.byteLength(data) : 0
    }
  };

  return new Promise((resolve, reject) => {
    const req = https.request(options, res => {
      let chunks = "";
      res.on("data", d => chunks += d);
      res.on("end", () => {
        if (res.statusCode >= 200 && res.statusCode < 300) {
          resolve(chunks ? JSON.parse(chunks) : {});
        } else {
          reject(`GitHub API error ${res.statusCode}: ${chunks}`);
        }
      });
    });

    req.on("error", reject);
    if (data) req.write(data);
    req.end();
  });
}

/**
 * Cria ou atualiza um GitHub Secret
 */
async function setGithubSecret(workspace, githubToken, name, value) {

  const { owner, repo } = await getRepoInfo(workspace);

  // 1. obter public key
  const keyData = await ghRequest(
    "GET",
    `/repos/${owner}/${repo}/actions/secrets/public-key`,
    githubToken
  );

  const key = keyData.key;
  const key_id = keyData.key_id;

  // 2. criptografar secret
  await sodium.ready;

  const messageBytes = Buffer.from(value);
  const keyBytes = sodium.from_base64(key, sodium.base64_variants.ORIGINAL);

  const encryptedBytes = sodium.crypto_box_seal(messageBytes, keyBytes);
  const encrypted = sodium.to_base64(encryptedBytes, sodium.base64_variants.ORIGINAL);

  // 3. enviar secret
  await ghRequest(
    "PUT",
    `/repos/${owner}/${repo}/actions/secrets/${name}`,
    githubToken,
    {
      encrypted_value: encrypted,
      key_id
    }
  );
}

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
  const total = doc.lineCount;

  const startSel = editor.selection.start.line;
  const endSel = editor.selection.end.line;

  const isFunction = (text) =>
    /(public|protected|private|static|\s)*function\s+[a-zA-Z0-9_]+\s*\(/.test(text);

  const isClass = (text) =>
    /^\s*(class|interface|trait|enum)\s+/.test(text);

  let start = -1;

  // 1️⃣ tentar encontrar função dentro da seleção
  for (let i = startSel; i <= endSel; i++) {
    if (isFunction(doc.lineAt(i).text)) {
      start = i;
      break;
    }
  }

  // 2️⃣ procurar função acima da seleção
  if (start === -1) {

    for (let i = startSel; i >= 0; i--) {

      const text = doc.lineAt(i).text;

      if (isFunction(text)) {
        start = i;
        break;
      }

      if (isClass(text)) {
        start = i;
        break;
      }

    }

  }

  // fallback
  if (start === -1) {
    return doc.getText(editor.selection);
  }

  // 3️⃣ encontrar fechamento do bloco
  let braceCount = 0;
  let foundOpen = false;
  let end = start;

  for (let i = start; i < total; i++) {

    const text = doc.lineAt(i).text;

    for (const char of text) {

      if (char === "{") {
        braceCount++;
        foundOpen = true;
      }

      if (char === "}") {
        braceCount--;
      }

    }

    if (foundOpen && braceCount === 0) {
      end = i;
      break;
    }

  }

  return doc.getText(
    new vscode.Range(start, 0, end + 1, 0)
  );

}

/* ======================
RUN ENGINE
====================== */

async function runPhp(context, editor) {



  oc.clear();
  oc.appendLine("[DocGen] Iniciando documentação...");
  oc.show(true);

  const { env } = getConfig();

  const phpPath = path.join(
  context.extensionPath,
    "runtime",
    "php",
    "win",
    "php.exe"
);

  const root = context.extensionPath;
  const engineDir = path.join(root, "engine");

  const script = path.join(engineDir, "bin", "run.php");
  const inputDir = path.join(engineDir, "input");
  const outputDir = path.join(engineDir, "output");

  const inputFile = path.join(inputDir, "entrada.php");

  await fs.promises.mkdir(inputDir, { recursive: true });
  await fs.promises.mkdir(outputDir, { recursive: true });

  let selected;

  if (editor.selection.isEmpty) {
    selected = editor.document.getText();
  } else {
    selected = expandSelection(editor);
  }
  const wrapped = sanitizeFragment(selected);

  await fs.promises.writeFile(inputFile, wrapped.code, "utf8");

  oc.appendLine("----- PHP enviado ao engine -----");
  oc.appendLine(wrapped.code);
  oc.appendLine("---------------------------------");



  const apiKey = await getApiKey(context);

  oc.appendLine("API KEY length: " + (apiKey ? apiKey.length : 0));

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

  const selectionStart = editor.selection.start.line;

  for (const item of map) {

    if (item.type === "class" && item.name === "__DocGenTemp") continue;

    const docBlock = docs[item.id];
    if (!docBlock) continue;

    // linha da assinatura no fragmento
    let targetLine = item.line - wrapped.offset;

    // converter para linha real do arquivo
    targetLine = selectionStart + targetLine - 1;

    if (targetLine < 0 || targetLine >= editor.document.lineCount) continue;

    const indent = editor.document.lineAt(targetLine).text.match(/^(\s*)/)?.[0] ?? "";

    inserts.push({
      line: targetLine,
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

async function ensurePreCommit(workspace) {

  try {
    await run("pre-commit --version", workspace);
    oc.appendLine("pre-commit já instalado.");
  } catch {
    oc.appendLine("Instalando pre-commit...");

    try {
      await run("pip install pre-commit", workspace);
    } catch {
      try {
        await run("pip3 install pre-commit", workspace);
      } catch {
        oc.appendLine("Falha ao instalar pre-commit automaticamente.");
        return;
      }
    }
  }

  try {
    await run("pre-commit install", workspace);
    oc.appendLine("Hooks do pre-commit instalados.");
  } catch (err) {
    oc.appendLine("Erro ao instalar hooks: " + err);
  }
}

async function ensureComposerInstall(dir) {

  const composerFile = path.join(dir, "composer.json");

  if (!fs.existsSync(composerFile)) {
    oc.appendLine("composer.json não encontrado em " + dir);
    return;
  }

  try {

    await run("composer --version", dir);
    oc.appendLine("Composer encontrado.");

  } catch {

    oc.appendLine("Composer não encontrado.");
    return;

  }

  try {

    oc.appendLine("Executando composer install em " + dir);
    await run("composer install --no-interaction --no-progress", dir);
    oc.appendLine("Dependências instaladas.");

  } catch (err) {

    oc.appendLine("Erro no composer install: " + err);

  }

}

async function ensureWorkflow(workspace, oc) {

  const wfDir = path.join(workspace, ".github", "workflows");
  const wfFile = path.join(wfDir, "docgen.yml");

  if (fs.existsSync(wfFile)) {
    oc.appendLine("Workflow já existe.");
    return;
  }

  oc.appendLine("Criando workflow do GitHub Actions...");

  await fs.promises.mkdir(wfDir, { recursive: true });

  const workflow = `name: DocGen Pipeline

on:
  push:
    branches:
      - master
      - main

jobs:

  docgen:

    runs-on: ubuntu-latest

    steps:

      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2

      - name: Install DocGen Engine
        run: |
          composer require filipe/docgen-engine --no-interaction || true

      - name: Run DocGen
        run: vendor/bin/docgen || true
        env:
          OPENAI_API_KEY: \${{ secrets.OPENAI_API_KEY }}

      - name: Generate phpDocumentor
        run: |
          composer require phpdocumentor/phpdocumentor --no-interaction || true
          vendor/bin/phpdocumentor --target docs || true

      - name: Upload docs
        uses: actions/upload-artifact@v4
        with:
          name: documentation
          path: docs
`;

  await fs.promises.writeFile(wfFile, workflow);

  oc.appendLine("Workflow criado com sucesso.");
}

async function pushToGit(context) {

  oc.appendLine("Executando pushToGit...");
  oc.show(true);

  const workspace = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;

  oc.appendLine("Workspace detectado: " + workspace);

  await ensureWorkflow(workspace, oc);

  if (!workspace) {
    vscode.window.showErrorMessage("Nenhum projeto aberto.");
    return;
  }

  oc.appendLine("Preparando variáveis...");

  let apiKey;
  try {
    apiKey = await getApiKey(context);
    oc.appendLine("API Key carregada");
  } catch (e) {
    oc.appendLine("Erro ao carregar API key: " + e);
  }

  const { env } = getConfig();
  oc.appendLine("Config carregada");

  let webhookUrl;
  try {
    webhookUrl = vscode.workspace
      .getConfiguration("phpDocgen")
      .get("n8nWebhook", "");
    oc.appendLine("Webhook carregado");
  } catch (e) {
    oc.appendLine("Erro webhook: " + e);
  }

  /* ======================
     Atualizar GitHub Secret
  ====================== */

  try {

    const githubToken = await context.secrets.get("docgen.github_token");

    if (!githubToken) {
      oc.appendLine("GitHub token não configurado.");
    } 
    else if (apiKey) {

      oc.appendLine("Atualizando secret OPENAI_API_KEY no GitHub...");

      await setGithubSecret(
        workspace,
        githubToken,
        "OPENAI_API_KEY",
        apiKey
      );

      oc.appendLine("Secret OPENAI_API_KEY atualizado.");

    }

  } catch (err) {

    oc.appendLine("Erro ao atualizar secret no GitHub: " + err);

  }

  /* ======================
     Preparar engine
  ====================== */

  const engineDir = path.join(context.extensionPath, "engine");

  await ensureComposerInstall(engineDir);

  await ensurePreCommit(workspace);

  /* ======================
     Auto documentar
  ====================== */

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

  const wfDir = path.join(workspace, ".github", "workflows");

  await fs.promises.mkdir(wfDir, { recursive: true });

  const wfFile = path.join(wfDir, "ci.yml");

  if (!fs.existsSync(wfFile)) {

    oc.appendLine("Criando workflow do GitHub Actions...");

    const workflow = `name: DocGen Pipeline

  on:
    push:
      branches:
        - master
        - main

  jobs:

    docgen:

      runs-on: ubuntu-latest

      steps:

        - uses: actions/checkout@v4

        - uses: shivammathur/setup-php@v2
          with:
            php-version: 8.2

        - name: Run DocGen
          run: php bin/run.php
          env:
            OPENAI_API_KEY: \${{ secrets.OPENAI_API_KEY }}
  `;

    await fs.promises.writeFile(wfFile, workflow);
  }

  /* ======================
     Git commit + push
  ====================== */

  try {

    await run("git add .", workspace);

    const message = await vscode.window.showInputBox({
      prompt: "Mensagem do commit",
      value: "DocGen update"
    });

    if (!message) {
      oc.appendLine("Commit cancelado.");
      return;
    }

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

async function activate(context) {

  const engineDir = path.join(context.extensionPath, "engine");

  await ensureComposerInstall(engineDir);

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

      const selected = editor.document.getText();
      const wrapped = sanitizeFragment(selected);

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