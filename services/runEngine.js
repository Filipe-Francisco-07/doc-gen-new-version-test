const vscode = require("vscode");
const { exec } = require("child_process");
const path = require("path");

function runEngine(context) {

    const workspace = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspace) {
        vscode.window.showErrorMessage("Nenhum workspace aberto.");
        return;
    }

    const enginePath = path.join(context.extensionPath, "engine", "bin", "run.php");

    const command = `php "${enginePath}" "${workspace}"`;

    const terminal = vscode.window.createTerminal("DocGen Engine");
    terminal.show();
    terminal.sendText(command);
}

module.exports = { runEngine };