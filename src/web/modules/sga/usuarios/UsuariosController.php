<?php
namespace modules\sga\usuarios;

use \Exception;
use \core\db\DB;
use \core\SGAContext;
use \core\util\Arrays;
use \core\http\AjaxResponse;
use \core\business\AcessoBusiness;
use \core\model\SequencialModel;
use \core\model\Usuario;
use \core\controller\CrudController;

/**
 * UsuariosController
 *
 * @author rogeriolino
 */
class UsuariosController extends CrudController {

    protected function createModel() {
        return new Usuario();
    }
    
    protected function requiredFields() {
        return array('login', 'nome', 'sobrenome');
    }
    
    public function edit(SGAContext $context) {
        parent::edit($context);
        // lotacoes do usuario
        $query = $this->em()->createQuery("SELECT e FROM \core\model\Lotacao e JOIN e.cargo c JOIN e.grupo g WHERE e.usuario = :usuario ORDER BY g.left DESC");
        $query->setParameter('usuario', $this->model->getId());
        $this->view()->assign('lotacoes', $query->getResult());
        // servicos do usuario
        $query = $this->em()->createQuery("SELECT e FROM \core\model\ServicoUsuario e WHERE e.usuario = :usuario");
        $query->setParameter('usuario', $this->model->getId());
        $this->view()->assign('servicos', $query->getResult());
        // unidades
        $query = $this->em()->createQuery("SELECT e FROM \core\model\Unidade e ORDER BY e.nome");
        $this->view()->assign('unidades', $query->getResult());
        // cargos disponiveis
        $query = $this->em()->createQuery("SELECT e FROM \core\model\Cargo e ORDER BY e.nome");
        $this->view()->assign('cargos', $query->getResult());
    }
    
    protected function preSave(SGAContext $context, SequencialModel $model) {
        $login = Arrays::value($_POST, 'login');
        if (!preg_match('/^[a-zA-Z0-9\.]+$/', $login)) {
            throw new Exception(_('O login deve conter somente letras e números.'));
        }
        if (strlen($login) < 5 || strlen($login) > 20) {
            throw new Exception(_('O login deve possuir entre 5 e 20 caracteres (letras ou números).'));
        }
        $lotacoes = Arrays::value($_POST, 'lotacoes', array());
        if (empty($lotacoes)) {
            throw new Exception(_('O usuário deve possuir pelo menos uma lotação.'));
        }
        if ($model->getId() == 0) {
            // para novos usuarios, tem que informar a senha
            $senha = Arrays::value($_POST, 'senha');
            $confirmacao = Arrays::value($_POST, 'senha2');
            // verifica e codifica a senha
            $model->setSenha(AcessoBusiness::verificaSenha($senha, $confirmacao));
            $model->setStatus(1);
            $model->setSessionId('');
        } else {
            $model->setStatus((int) Arrays::value($_POST, 'status'));
        }
        // verificando novo login ou alteracao
        $query = $this->em()->createQuery("SELECT COUNT(e) as total FROM \core\model\Usuario e WHERE e.login = :login AND e.id != :id");
        $query->setParameter('login', $model->getLogin());
        $query->setParameter('id', $model->getId());
        $rs = $query->getSingleResult();
        if ($rs['total']) {
            throw new \Exception(_('O login informado já está cadastrado para outro usuário.'));
        }
    }
    
    protected function postSave(SGAContext $context, SequencialModel $model) {
        $conn = $this->em()->getConnection();
        // lotacoes - atualizando permissoes do cargo
        $query = $this->em()->createQuery("DELETE FROM \core\model\Lotacao e WHERE e.usuario = :usuario");
        $query->setParameter('usuario', $model->getId());
        $query->execute();
        $lotacoes = Arrays::value($_POST, 'lotacoes', array());
        if (!empty($lotacoes)) {
            $stmt = $conn->prepare("INSERT INTO usu_grup_cargo (id_grupo, id_cargo, id_usu) VALUES (:grupo, :cargo, :usuario)");
            foreach ($lotacoes as $lotacao) {
                $value = explode(',', $lotacao);
                $stmt->bindValue('grupo', $value[0], \PDO::PARAM_INT);
                $stmt->bindValue('cargo', $value[1], \PDO::PARAM_INT);
                $stmt->bindValue('usuario', $model->getId(), \PDO::PARAM_INT);
                $stmt->execute();
            }
        }
        // servicos
        $query = $this->em()->createQuery("DELETE FROM \core\model\ServicoUsuario e WHERE e.usuario = :usuario");
        $query->setParameter('usuario', $model->getId());
        $query->execute();
        $servicos = Arrays::value($_POST, 'servicos', array());
        if (!empty($servicos)) {
            $stmt = $conn->prepare("INSERT INTO usu_serv (id_uni, id_serv, id_usu) VALUES (:unidade, :servico, :usuario)");
            foreach ($servicos as $servico) {
                $value = explode(',', $servico);
                $stmt->bindValue('unidade', $value[0], \PDO::PARAM_INT);
                $stmt->bindValue('servico', $value[1], \PDO::PARAM_INT);
                $stmt->bindValue('usuario', $model->getId(), \PDO::PARAM_INT);
                $stmt->execute();
            }
        }
    }
    
    protected function preDelete(SGAContext $context, SequencialModel $model) {
        if ($context->getUser()->getId() === $model->getId()) {
            throw new \Exception(_('Não é possível excluir si próprio.'));
        }
        // verificando a quantidade de atendimentos do usuario
        $total = 0;
        $models = array('Atendimento', 'ViewAtendimento');
        foreach ($models as $atendimentoModel) {
            $query = $this->em()->createQuery("SELECT COUNT(e) as total FROM \core\model\\$atendimentoModel e WHERE e.usuario = :usuario");
            $query->setParameter('usuario', $model->getId());
            $rs = $query->getSingleResult();
            $total += $rs['total'];
        }
        if ($total > 0) {
            throw new \Exception(_('Não é possível excluir esse usuário pois o mesmo já realizou atendimentos.'));
        }
        // excluindo vinculos do usuario (servicos e lotacoes)
        $models = array('ServicoUsuario', 'Lotacao');
        foreach ($models as $vinculoModel) {
            $query = $this->em()->createQuery("DELETE FROM \core\model\\$vinculoModel e WHERE e.usuario = :usuario");
            $query->setParameter('usuario', $model->getId());
            $query->execute();
        }
    }

    protected function search($arg) {
        $query = $this->em()->createQuery("SELECT e FROM \core\model\Usuario e WHERE UPPER(e.nome) LIKE :arg OR UPPER(e.login) LIKE :arg");
        $query->setParameter('arg', $arg);
        return $query->getResult();
    }
    
    /**
     * Retorna os grupos disponíveis para serem atribuidos ao usuário. Descartando os grupos com ids informados no parâmetro exceto.
     * @param array $exceto
     */
    private function grupos_disponiveis(array $exceto) {
        // grupos disponiveis (grupos que o usuario nao esta vinculados e que nao sao filhos e nem pai do que esta)
        $query = $this->em()->createQuery("
            SELECT 
                e
            FROM 
                \core\model\Grupo e 
            WHERE 
                NOT EXISTS (
                    SELECT 
                        g2.id 
                    FROM 
                        \core\model\Grupo g2 
                    WHERE 
                        (
                            g2.left <= e.left AND g2.right >= e.right OR
                            g2.left >= e.left AND g2.right <= e.right 
                        )
                        AND g2.id IN (:exceto))
            ORDER BY 
                e.left, e.nome
        ");
        $query->setParameter('exceto', $exceto);
        return $query->getResult();
    }
    
    /**
     * Retorna os grupos disponíveis para serem atribuidos ao usuário. Descartando os grupos com ids informados no parâmetro exceto.
     * @param \core\SGAContext $context
     */
    public function grupos(SGAContext $context) {
        $exceto = $context->getRequest()->getParameter('exceto');
        $exceto = Arrays::valuesToInt(explode(',', $exceto));
        $response = new AjaxResponse(true);
        $grupos = $this->grupos_disponiveis($exceto);
        foreach ($grupos as $g) {
            $response->data[] = array('id' => $g->getId(), 'nome' => $g->getNome());
        }
        $context->getResponse()->jsonResponse($response);
    }

    /**
     * Retorna as permissões do cargo informado
     * @param \core\SGAContext $context
     */
    public function permissoes_cargo(SGAContext $context) {
        $response = new AjaxResponse(true);
        $id = (int) $context->getRequest()->getParameter('cargo');
        $query = $this->em()->createQuery("SELECT m.nome FROM \core\model\Permissao e JOIN e.modulo m WHERE e.cargo = :cargo ORDER BY m.nome");
        $query->setParameter('cargo', $id);
        $response->data = $query->getResult();
        $context->getResponse()->jsonResponse($response);
    }

    /**
     * Retorna os serviços habilitados na unidade informada. Descartando os serviços com ids informados no parâmetro exceto
     * @param \core\SGAContext $context
     */
    public function servicos_unidade(SGAContext $context) {
        $response = new AjaxResponse(true);
        $id = (int) $context->getRequest()->getParameter('unidade');
        $exceto = $context->getRequest()->getParameter('exceto');
        $exceto = Arrays::valuesToInt(explode(',', $exceto));
        $query = $this->em()->createQuery("
            SELECT 
                s.id, e.nome 
            FROM 
                \core\model\ServicoUnidade e 
                JOIN e.unidade u 
                JOIN e.servico s 
            WHERE 
                e.status = 1 AND 
                u = :unidade AND
                s.id NOT IN (:exceto)
            ORDER BY 
                e.nome
        ");
        $query->setParameter('unidade', $id);
        $query->setParameter('exceto', $exceto);
        $response->data = $query->getResult();
        $context->getResponse()->jsonResponse($response);
    }
    
    /**
     * Altera a senha do usuario que está sendo editado
     * @param \core\SGAContext $context
     */
    public function alterar_senha(SGAContext $context) {
        $response = new AjaxResponse();
        $id = (int) $context->getRequest()->getParameter('id');
        $senha = $context->getRequest()->getParameter('senha');
        $confirmacao = $context->getRequest()->getParameter('confirmacao');
        $usuario = $this->findById($id);
        if ($usuario) {
            try {
                $hash = AcessoBusiness::verificaSenha($senha, $confirmacao);
                $query = $this->em()->createQuery("UPDATE \core\model\Usuario u SET u.senha = :senha WHERE u.id = :id");
                $query->setParameter('senha', $hash);
                $query->setParameter('id', $usuario->getId());
                $query->execute();
                $response->success = true;
            } catch (Exception $e) {
                $response->message = $e->getMessage();
            }
        } else {
            $response->message = _('Usuário inválido');
        }
        $context->getResponse()->jsonResponse($response);
    }
    
}
