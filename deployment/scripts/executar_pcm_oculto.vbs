Option Explicit
Dim shell, projectPath, phpPath, exitCode
If WScript.Arguments.Count <> 2 Then
    WScript.Echo "Uso: executar_pcm_oculto.vbs RAIZ_DO_PROJETO CAMINHO_DO_PHP.EXE"
    WScript.Quit 1
End If
projectPath = WScript.Arguments(0)
phpPath = WScript.Arguments(1)
Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = projectPath
exitCode = shell.Run("""" & phpPath & """ bin\cake.php import_pending_reports", 0, True)
WScript.Quit exitCode
