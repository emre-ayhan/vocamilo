<?php

return [
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser texto.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'confirmed' => 'A confirmação de :attribute não coincide.',
    'unique' => 'O valor de :attribute já está em uso.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'array' => 'O campo :attribute deve ser uma lista.',
    'distinct' => 'O campo :attribute tem um valor duplicado.',
    'exists' => 'O valor selecionado para :attribute é inválido.',
    'file' => 'O campo :attribute deve ser um ficheiro.',
    'mimes' => 'O campo :attribute deve ser um ficheiro do tipo: :values.',
    'mimetypes' => 'O campo :attribute deve ser um ficheiro do tipo: :values.',
    'max' => ['string' => 'O campo :attribute não pode ter mais de :max caracteres.', 'numeric' => 'O campo :attribute não pode ser superior a :max.', 'array' => 'O campo :attribute não pode ter mais de :max elementos.', 'file' => 'O ficheiro :attribute não pode ter mais de :max kilobytes.'],
    'min' => ['string' => 'O campo :attribute deve ter pelo menos :min caracteres.', 'numeric' => 'O campo :attribute deve ser pelo menos :min.', 'array' => 'O campo :attribute deve ter pelo menos :min elementos.'],
    'size' => ['array' => 'O campo :attribute deve ter :size elementos.'],
    'password' => ['letters' => 'A palavra-passe deve incluir pelo menos uma letra.', 'mixed' => 'A palavra-passe deve incluir maiúsculas e minúsculas.', 'numbers' => 'A palavra-passe deve incluir um número.', 'symbols' => 'A palavra-passe deve incluir um símbolo.'],
    'attributes' => ['name' => 'nome', 'email' => 'e-mail', 'password' => 'palavra-passe', 'title' => 'título', 'description' => 'descrição', 'term' => 'palavra', 'definition' => 'definição', 'note' => 'nota', 'file' => 'ficheiro', 'locale' => 'idioma', 'word_ids' => 'palavras', 'answers' => 'respostas'],
];
