<?php
include 'conexao.php';

/**
 * Sistema de Geração Automática de Calendário de Aulas
 * SENAI - Gestão de Agenda de Professores
 */

class GeradorCalendario {
    private $conn;
    private $conflitos = [];
    
    public function __construct($conexao) {
        $this->conn = $conexao;
    }
    
    /**
     * Gera o calendário completo para um curso específico
     */
    public function gerarCalendarioCurso($idCurso) {
        // Buscar informações do curso
        $curso = $this->buscarCurso($idCurso);
        if (!$curso) {
            return ['success' => false, 'message' => 'Curso não encontrado'];
        }
        
        // Buscar competências do curso
        $competencias = $this->buscarCompetenciasCurso($idCurso);
        if (empty($competencias)) {
            return ['success' => false, 'message' => 'Nenhuma competência associada ao curso'];
        }
        
        // Gerar datas de aula
        $datasAulas = $this->gerarDatasAulas($curso);
        
        // Distribuir competências nas datas
        $agendaGerada = $this->distribuirCompetencias($competencias, $datasAulas, $idCurso);
        
        // Alocar professores
        $resultado = $this->alocarProfessores($agendaGerada, $idCurso);
        
        return $resultado;
    }
    
    /**
     * Busca dados do curso
     */
    private function buscarCurso($idCurso) {
        $sql = "SELECT * FROM cursos WHERE IDcurso = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $idCurso);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Busca competências do curso ordenadas
     */
    private function buscarCompetenciasCurso($idCurso) {
        $sql = "SELECT c.*, cc.ordem 
                FROM competencias c
                INNER JOIN competencia_comp cc ON c.IDcompetencia = cc.IDcompetencia
                WHERE cc.IDcurso = ?
                ORDER BY cc.ordem ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $idCurso);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $competencias = [];
        while ($row = $result->fetch_assoc()) {
            $competencias[] = $row;
        }
        return $competencias;
    }
    
    /**
     * Gera todas as datas de aula baseado nos dias da semana do curso
     */
    private function gerarDatasAulas($curso) {
        $dataInicio = new DateTime($curso['data_inicio']);
        $dataFim = new DateTime($curso['data_fim']);
        $diasSemana = explode(',', $curso['dias_semana']);
        
        // Mapear dias para números (1=Segunda, 7=Domingo)
        $mapaDias = [
            'Segunda' => 1, 'Terça' => 2, 'Quarta' => 3,
            'Quinta' => 4, 'Sexta' => 5, 'Sábado' => 6, 'Domingo' => 7
        ];
        
        $diasNumeros = array_map(fn($dia) => $mapaDias[$dia], $diasSemana);
        
        $datas = [];
        $dataAtual = clone $dataInicio;
        
        while ($dataAtual <= $dataFim) {
            $diaSemana = (int)$dataAtual->format('N'); // 1-7 (Segunda-Domingo)
            
            if (in_array($diaSemana, $diasNumeros)) {
                $datas[] = [
                    'data' => $dataAtual->format('Y-m-d'),
                    'turno' => $curso['turno']
                ];
            }
            
            $dataAtual->modify('+1 day');
        }
        
        return $datas;
    }
    
    /**
     * Distribui competências nas datas disponíveis
     */
    private function distribuirCompetencias($competencias, $datasAulas, $idCurso) {
        $agenda = [];
        $indiceData = 0;
        $totalDatas = count($datasAulas);
        
        foreach ($competencias as $competencia) {
            // Calcular quantas aulas necessárias (assumindo 4h por aula)
            $aulasNecessarias = ceil($competencia['carga_horaria'] / 4);
            
            for ($i = 0; $i < $aulasNecessarias; $i++) {
                if ($indiceData >= $totalDatas) {
                    break; // Não há mais datas disponíveis
                }
                
                $dataAula = $datasAulas[$indiceData];
                
                // Definir horários baseado no turno
                $horarios = $this->obterHorariosTurno($dataAula['turno']);
                
                $agenda[] = [
                    'IDcurso' => $idCurso,
                    'IDcompetencia' => $competencia['IDcompetencia'],
                    'data_aula' => $dataAula['data'],
                    'hora_inicio' => $horarios['inicio'],
                    'hora_fim' => $horarios['fim'],
                    'competencia_nome' => $competencia['competencia']
                ];
                
                $indiceData++;
            }
        }
        
        return $agenda;
    }
    
    /**
     * Define horários baseado no turno
     */
    private function obterHorariosTurno($turno) {
        $horarios = [
            'Manhã' => ['inicio' => '08:00:00', 'fim' => '12:00:00'],
            'Tarde' => ['inicio' => '13:00:00', 'fim' => '17:00:00'],
            'Noite' => ['inicio' => '18:30:00', 'fim' => '22:30:00']
        ];
        
        return $horarios[$turno] ?? ['inicio' => '08:00:00', 'fim' => '12:00:00'];
    }
    
    /**
     * Aloca professores para cada aula
     */
    private function alocarProfessores($agenda, $idCurso) {
        $aulasInseridas = 0;
        $this->conflitos = [];
        
        foreach ($agenda as $aula) {
            // Buscar professores aptos para a competência
            $professores = $this->buscarProfessoresAptos($aula['IDcompetencia']);
            
            if (empty($professores)) {
                $this->conflitos[] = "Nenhum professor disponível para: " . $aula['competencia_nome'];
                continue;
            }
            
            // Tentar alocar um professor (prioriza por nível de conhecimento e disponibilidade)
            $professorAlocado = null;
            
            foreach ($professores as $professor) {
                if ($this->verificarDisponibilidade($professor['IDprofessor'], $aula['data_aula'], 
                                                     $aula['hora_inicio'], $aula['hora_fim'])) {
                    $professorAlocado = $professor;
                    break;
                }
            }
            
            if ($professorAlocado) {
                // Inserir na agenda
                $this->inserirAula($professorAlocado['IDprofessor'], $aula);
                $aulasInseridas++;
            } else {
                $this->conflitos[] = "Conflito de horário em " . $aula['data_aula'] . 
                                    " para " . $aula['competencia_nome'];
                // Registrar conflito no banco
                $this->registrarConflito($professores[0]['IDprofessor'], $aula);
            }
        }
        
        return [
            'success' => true,
            'aulas_inseridas' => $aulasInseridas,
            'total_aulas' => count($agenda),
            'conflitos' => $this->conflitos
        ];
    }
    
    /**
     * Busca professores aptos para uma competência
     */
    private function buscarProfessoresAptos($idCompetencia) {
        $sql = "SELECT p.*, pc.nivel_conhecimento 
                FROM professor p
                INNER JOIN professor_competencia pc ON p.IDprofessor = pc.IDprofessor
                WHERE pc.IDcompetencia = ? AND p.status = 'ativo'
                ORDER BY 
                    CASE pc.nivel_conhecimento
                        WHEN 'expert' THEN 1
                        WHEN 'avançado' THEN 2
                        WHEN 'intermediário' THEN 3
                        WHEN 'básico' THEN 4
                    END";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $idCompetencia);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $professores = [];
        while ($row = $result->fetch_assoc()) {
            $professores[] = $row;
        }
        return $professores;
    }
    
    /**
     * Verifica disponibilidade do professor
     */
    private function verificarDisponibilidade($idProfessor, $data, $horaInicio, $horaFim) {
        $sql = "SELECT COUNT(*) as conflitos 
                FROM agenda 
                WHERE IDprofessor = ? 
                AND data_aula = ? 
                AND status != 'cancelado'
                AND (
                    (hora_inicio < ? AND hora_fim > ?) OR
                    (hora_inicio < ? AND hora_fim > ?) OR
                    (hora_inicio >= ? AND hora_fim <= ?)
                )";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isssssss", $idProfessor, $data, $horaFim, $horaInicio, 
                         $horaFim, $horaInicio, $horaInicio, $horaFim);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['conflitos'] == 0;
    }
    
    /**
     * Insere aula na agenda
     */
    private function inserirAula($idProfessor, $aula) {
        $sql = "INSERT INTO agenda (IDprofessor, IDcurso, IDcompetencia, data_aula, 
                hora_inicio, hora_fim, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'agendado')";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiisss", $idProfessor, $aula['IDcurso'], $aula['IDcompetencia'], 
                         $aula['data_aula'], $aula['hora_inicio'], $aula['hora_fim']);
        return $stmt->execute();
    }
    
    /**
     * Registra conflito no banco de dados
     */
    private function registrarConflito($idProfessor, $aula) {
        $sql = "INSERT INTO conflitos (IDprofessor, data_conflito, hora_inicio, hora_fim, 
                tipo_conflito, descricao) 
                VALUES (?, ?, ?, ?, 'sobreposição', ?)";
        
        $descricao = "Conflito ao alocar: " . $aula['competencia_nome'];
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("issss", $idProfessor, $aula['data_aula'], $aula['hora_inicio'], 
                         $aula['hora_fim'], $descricao);
        $stmt->execute();
    }
}

// Executar geração se for chamado diretamente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gerar_calendario'])) {
    $idCurso = intval($_POST['id_curso']);
    
    $gerador = new GeradorCalendario($conn);
    $resultado = $gerador->gerarCalendarioCurso($idCurso);
    
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
