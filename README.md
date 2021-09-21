# plugin-FinancialValidador
Plugin que agrega funcionalidade de gerenciamento dos pagamentos

## Exemplo de Configuração

```
     'FinancialValidador' => [
        'namespace' => "FinancialValidador",
        'config' => [
            'slug' => 'financeiro',
            'name' => 'Validador Financeiro',
            'is_opportunity_managed_handler' => function ($opportunity) {
                return ($opportunity->id == env("FINANTIAL_VALIDATOR_OPPORTUNITY_ID", 137));
            },
        ]
    ]   
```