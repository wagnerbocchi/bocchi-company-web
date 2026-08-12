---
title: Metade da revolução de IA cabe em 16 bits. Literalmente.
slug: metade-da-revolucao
date: 2026-08-12
lang: pt
excerpt: ''
tags:
  - AI
  - Devops
  - Ai Engineer
cover: /assets/img/blog/1780025129303.jpg
cover_alt: ''
draft: false
updated: ''
---

Toda vez que alguém me pergunta como modelos gigantes rodam sem derreter uma GPU, eu volto pro mesmo lugar: FP16.

É um formato de ponto flutuante de 16 bits. A receita é simples de descrever e brutal de eficiente:

→ 1 bit pro sinal → 5 bits pro expoente → 10 bits pra mantissa
Comparado ao FP32 (o "padrão histórico" de 32 bits), você corta o tamanho de cada número pela metade. 

E é aí que a mágica acontece:
 Memória você gasta metade. Modelo que não cabia, passa a caber.
 Velocidade em hardware com aceleração de 16 bits (oi, Tensor Cores da NVIDIA), o throughput praticamente dobra. 
Escala dá pra treinar redes maiores ou rodar inferência pesada sem estourar a VRAM.

Mas e sempre tem um "mas" não é almoço grátis.

Com só 5 bits de expoente, o intervalo dinâmico encolhe. Tradução: você fica muito mais exposto a overflow (número grande demais vira infinito) e underflow (número pequeno demais vira zero, e o gradiente simplesmente some).

Por isso, no treino de redes neurais, raramente se usa FP16 puro. Entra o loss scaling: você multiplica a loss por um fator antes do backward pra empurrar os gradientes pequenos de volta pra faixa representável, e divide depois. É o truque que segura a estabilidade numérica.

Moral da história: FP16 não é "FP32 mais barato". É uma troca consciente de alcance por eficiência e saber quando essa troca compensa é o que separa quem usa de quem entende.
