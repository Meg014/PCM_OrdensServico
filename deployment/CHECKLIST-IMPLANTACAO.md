# Checklist de implantação PCM

Para troca do banco atual, siga primeiro o [checklist de migração e os comandos MariaDB](../docs/implantacao-mariadb.md#8-checklist-de-migração).

- [ ] Backup lógico do banco antigo validado, sem modificar seus arquivos físicos
- [ ] MariaDB instalado como serviço independente do XAMPP, sem conflito de porta
- [ ] Histórico restaurado no novo banco vazio, quando necessário
- [ ] Todos os `DB_*` definidos no mesmo `config/.env` para web, CLI e agendador
- [ ] `pcm_health` informa o destino correto e retorna sucesso

- [ ] PHP 8.2 ou superior instalado
- [ ] Extensões PHP obrigatórias habilitadas
- [ ] Composer 2 instalado
- [ ] MariaDB disponível
- [ ] Banco `pcm` criado com `utf8mb4`
- [ ] Migrations executadas
- [ ] Credenciais configuradas como variáveis de ambiente
- [ ] `PCM_REPORT_PATH` configurado
- [ ] Usuário do serviço possui leitura no compartilhamento UNC
- [ ] Pastas de processados, erros, logs e tmp possuem escrita
- [ ] Aplicação acessa o banco
- [ ] Aplicação acessa o CSV
- [ ] Importação inicial testada
- [ ] Dashboard validado contra a contagem do CSV
- [ ] Agendador configurado para cinco minutos
- [ ] Importação automática validada
- [ ] Hash duplicado validado
- [ ] Logs validados
- [ ] `DEBUG=false`
- [ ] DocumentRoot aponta exclusivamente para `webroot`
- [ ] Acesso restrito à rede interna/controles corporativos
- [ ] Backup e retenção definidos
- [ ] Conta técnica e senha administradas pelo TI
- [ ] Procedimento de rollback testado
