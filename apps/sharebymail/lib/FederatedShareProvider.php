<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\ShareByMail;

use OCP\Files\Node;
use OCP\Share;
use OCP\Share\IShare;
use OCP\Share\IShareProvider;

/**
 * Class ShareByMail
 *
 * @package OCA\ShareByMail
 */
class ShareByMailProvider implements IShareProvider {

	const SHARE_TYPE_MAIL = Share::SHARE_TYPE_EMAIL;

	/**
	 * Return the identifier of this provider.
	 *
	 * @return string Containing only [a-zA-Z0-9]
	 */
	public function identifier() {
		return 'ocShareByMail';
	}

	/**
	 * Share a path
	 *
	 * @param IShare $share
	 * @return IShare The share object
	 * @throws ShareNotFound
	 * @throws \Exception
	 */
	public function create(IShare $share) {

		$shareWith = $share->getSharedWith();
		$itemSource = $share->getNodeId();
		$itemType = $share->getNodeType();
		$permissions = $share->getPermissions();
		$sharedBy = $share->getSharedBy();

		/*
		 * Check if file is not already shared with the remote user
		 */
		$alreadyShared = $this->getSharedWith($shareWith, self::SHARE_TYPE_REMOTE, $share->getNode(), 1, 0);
		if (!empty($alreadyShared)) {
			$message = 'Sharing %s failed, because this item is already shared with %s';
			$message_t = $this->l->t('Sharing %s failed, because this item is already shared with %s', array($share->getNode()->getName(), $shareWith));
			$this->logger->debug(sprintf($message, $share->getNode()->getName(), $shareWith), ['app' => 'Federated File Sharing']);
			throw new \Exception($message_t);
		}


		// don't allow federated shares if source and target server are the same
		list($user, $remote) = $this->addressHandler->splitUserRemote($shareWith);
		$currentServer = $this->addressHandler->generateRemoteURL();
		$currentUser = $sharedBy;
		if ($this->addressHandler->compareAddresses($user, $remote, $currentUser, $currentServer)) {
			$message = 'Not allowed to create a federated share with the same user.';
			$message_t = $this->l->t('Not allowed to create a federated share with the same user');
			$this->logger->debug($message, ['app' => 'Federated File Sharing']);
			throw new \Exception($message_t);
		}

		$share->setSharedWith($user . '@' . $remote);

		try {
			$remoteShare = $this->getShareFromExternalShareTable($share);
		} catch (ShareNotFound $e) {
			$remoteShare = null;
		}

		if ($remoteShare) {
			try {
				$uidOwner = $remoteShare['owner'] . '@' . $remoteShare['remote'];
				$shareId = $this->addShareToDB($itemSource, $itemType, $shareWith, $sharedBy, $uidOwner, $permissions, 'tmp_token_' . time());
				$share->setId($shareId);
				list($token, $remoteId) = $this->askOwnerToReShare($shareWith, $share, $shareId);
				// remote share was create successfully if we get a valid token as return
				$send = is_string($token) && $token !== '';
			} catch (\Exception $e) {
				// fall back to old re-share behavior if the remote server
				// doesn't support flat re-shares (was introduced with Nextcloud 9.1)
				$this->removeShareFromTable($share);
				$shareId = $this->createFederatedShare($share);
			}
			if ($send) {
				$this->updateSuccessfulReshare($shareId, $token);
				$this->storeRemoteId($shareId, $remoteId);
			} else {
				$this->removeShareFromTable($share);
				$message_t = $this->l->t('File is already shared with %s', [$shareWith]);
				throw new \Exception($message_t);
			}

		} else {
			$shareId = $this->createFederatedShare($share);
		}

		$data = $this->getRawShare($shareId);
		return $this->createShareObject($data);
	}

	/**
	 * add share to the database and return the ID
	 *
	 * @param int $itemSource
	 * @param string $itemType
	 * @param string $shareWith
	 * @param string $sharedBy
	 * @param string $uidOwner
	 * @param int $permissions
	 * @param string $token
	 * @return int
	 */
	private function addShareToDB($itemSource, $itemType, $shareWith, $sharedBy, $uidOwner, $permissions, $token) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert('share')
			->setValue('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE))
			->setValue('item_type', $qb->createNamedParameter($itemType))
			->setValue('item_source', $qb->createNamedParameter($itemSource))
			->setValue('file_source', $qb->createNamedParameter($itemSource))
			->setValue('share_with', $qb->createNamedParameter($shareWith))
			->setValue('uid_owner', $qb->createNamedParameter($uidOwner))
			->setValue('uid_initiator', $qb->createNamedParameter($sharedBy))
			->setValue('permissions', $qb->createNamedParameter($permissions))
			->setValue('token', $qb->createNamedParameter($token))
			->setValue('stime', $qb->createNamedParameter(time()));

		/*
		 * Added to fix https://github.com/owncloud/core/issues/22215
		 * Can be removed once we get rid of ajax/share.php
		 */
		$qb->setValue('file_target', $qb->createNamedParameter(''));

		$qb->execute();
		$id = $qb->getLastInsertId();

		return (int)$id;
	}

	/**
	 * Update a share
	 *
	 * @param IShare $share
	 * @return IShare The share object
	 */
	public function update(IShare $share) {
		/*
		 * We allow updating the permissions of federated shares
		 */
		$qb = $this->dbConnection->getQueryBuilder();
			$qb->update('share')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($share->getId())))
				->set('permissions', $qb->createNamedParameter($share->getPermissions()))
				->set('uid_owner', $qb->createNamedParameter($share->getShareOwner()))
				->set('uid_initiator', $qb->createNamedParameter($share->getSharedBy()))
				->execute();

		// send the updated permission to the owner/initiator, if they are not the same
		if ($share->getShareOwner() !== $share->getSharedBy()) {
			$this->sendPermissionUpdate($share);
		}

		return $share;
	}

	/**
	 * @inheritdoc
	 */
	public function move(IShare $share, $recipient) {
		/*
		 * This function does nothing yet as it is just for outgoing
		 * federated shares.
		 */
		return $share;
	}

	/**
	 * Delete a share (owner unShares the file)
	 *
	 * @param IShare $share
	 */
	public function delete(IShare $share) {

		list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedWith());

		$isOwner = false;

		$this->removeShareFromTable($share);

		// if the local user is the owner we can send the unShare request directly...
		if ($this->userManager->userExists($share->getShareOwner())) {
			$this->notifications->sendRemoteUnShare($remote, $share->getId(), $share->getToken());
			$this->revokeShare($share, true);
			$isOwner = true;
		} else { // ... if not we need to correct ID for the unShare request
			$remoteId = $this->getRemoteId($share);
			$this->notifications->sendRemoteUnShare($remote, $remoteId, $share->getToken());
			$this->revokeShare($share, false);
		}

		// send revoke notification to the other user, if initiator and owner are not the same user
		if ($share->getShareOwner() !== $share->getSharedBy()) {
			$remoteId = $this->getRemoteId($share);
			if ($isOwner) {
				list(, $remote) = $this->addressHandler->splitUserRemote($share->getSharedBy());
			} else {
				list(, $remote) = $this->addressHandler->splitUserRemote($share->getShareOwner());
			}
			$this->notifications->sendRevokeShare($remote, $remoteId, $share->getToken());
		}
	}

	/**
	 * @inheritdoc
	 */
	public function deleteFromSelf(IShare $share, $recipient) {
		// nothing to do here. Technically deleteFromSelf in the context of federated
		// shares is a umount of a external storage. This is handled here
		// apps/files_sharing/lib/external/manager.php
		// TODO move this code over to this app
		return;
	}

	/**
	 * @inheritdoc
	 */
	public function getSharesBy($userId, $shareType, $node, $reshares, $limit, $offset) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
			->from('share');

		$qb->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)));

		/**
		 * Reshares for this user are shares where they are the owner.
		 */
		if ($reshares === false) {
			//Special case for old shares created via the web UI
			$or1 = $qb->expr()->andX(
				$qb->expr()->eq('uid_owner', $qb->createNamedParameter($userId)),
				$qb->expr()->isNull('uid_initiator')
			);

			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('uid_initiator', $qb->createNamedParameter($userId)),
					$or1
				)
			);
		} else {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('uid_owner', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('uid_initiator', $qb->createNamedParameter($userId))
				)
			);
		}

		if ($node !== null) {
			$qb->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($node->getId())));
		}

		if ($limit !== -1) {
			$qb->setMaxResults($limit);
		}

		$qb->setFirstResult($offset);
		$qb->orderBy('id');

		$cursor = $qb->execute();
		$shares = [];
		while($data = $cursor->fetch()) {
			$shares[] = $this->createShareObject($data);
		}
		$cursor->closeCursor();

		return $shares;
	}

	/**
	 * @inheritdoc
	 */
	public function getShareById($id, $recipientId = null) {
		$qb = $this->dbConnection->getQueryBuilder();

		$qb->select('*')
			->from('share')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)));

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ShareNotFound();
		}

		try {
			$share = $this->createShareObject($data);
		} catch (InvalidShare $e) {
			throw new ShareNotFound();
		}

		return $share;
	}

	/**
	 * Get shares for a given path
	 *
	 * @param \OCP\Files\Node $path
	 * @return IShare[]
	 */
	public function getSharesByPath(Node $path) {
		$qb = $this->dbConnection->getQueryBuilder();

		$cursor = $qb->select('*')
			->from('share')
			->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($path->getId())))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)))
			->execute();

		$shares = [];
		while($data = $cursor->fetch()) {
			$shares[] = $this->createShareObject($data);
		}
		$cursor->closeCursor();

		return $shares;
	}

	/**
	 * @inheritdoc
	 */
	public function getSharedWith($userId, $shareType, $node, $limit, $offset) {
		/** @var IShare[] $shares */
		$shares = [];

		//Get shares directly with this user
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('*')
			->from('share');

		// Order by id
		$qb->orderBy('id');

		// Set limit and offset
		if ($limit !== -1) {
			$qb->setMaxResults($limit);
		}
		$qb->setFirstResult($offset);

		$qb->where($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)));
		$qb->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($userId)));

		// Filter by node if provided
		if ($node !== null) {
			$qb->andWhere($qb->expr()->eq('file_source', $qb->createNamedParameter($node->getId())));
		}

		$cursor = $qb->execute();

		while($data = $cursor->fetch()) {
			$shares[] = $this->createShareObject($data);
		}
		$cursor->closeCursor();


		return $shares;
	}

	/**
	 * Get a share by token
	 *
	 * @param string $token
	 * @return IShare
	 * @throws ShareNotFound
	 */
	public function getShareByToken($token) {
		$qb = $this->dbConnection->getQueryBuilder();

		$cursor = $qb->select('*')
			->from('share')
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter(self::SHARE_TYPE_REMOTE)))
			->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->execute();

		$data = $cursor->fetch();

		if ($data === false) {
			throw new ShareNotFound('Share not found', $this->l->t('Could not find share'));
		}

		try {
			$share = $this->createShareObject($data);
		} catch (InvalidShare $e) {
			throw new ShareNotFound('Share not found', $this->l->t('Could not find share'));
		}

		return $share;
	}

	/**
	 * Create a share object from an database row
	 *
	 * @param array $data
	 * @return IShare
	 * @throws InvalidShare
	 * @throws ShareNotFound
	 */
	private function createShareObject($data) {

		$share = new Share($this->rootFolder, $this->userManager);
		$share->setId((int)$data['id'])
			->setShareType((int)$data['share_type'])
			->setPermissions((int)$data['permissions'])
			->setTarget($data['file_target'])
			->setMailSend((bool)$data['mail_send'])
			->setToken($data['token']);

		$shareTime = new \DateTime();
		$shareTime->setTimestamp((int)$data['stime']);
		$share->setShareTime($shareTime);
		$share->setSharedWith($data['share_with']);

		if ($data['uid_initiator'] !== null) {
			$share->setShareOwner($data['uid_owner']);
			$share->setSharedBy($data['uid_initiator']);
		} else {
			//OLD SHARE
			$share->setSharedBy($data['uid_owner']);
			$path = $this->getNode($share->getSharedBy(), (int)$data['file_source']);

			$owner = $path->getOwner();
			$share->setShareOwner($owner->getUID());
		}

		$share->setNodeId((int)$data['file_source']);
		$share->setNodeType($data['item_type']);

		$share->setProviderId($this->identifier());

		return $share;
	}

	/**
	 * Get the node with file $id for $user
	 *
	 * @param string $userId
	 * @param int $id
	 * @return \OCP\Files\File|\OCP\Files\Folder
	 * @throws InvalidShare
	 */
	private function getNode($userId, $id) {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (NotFoundException $e) {
			throw new InvalidShare();
		}

		$nodes = $userFolder->getById($id);

		if (empty($nodes)) {
			throw new InvalidShare();
		}

		return $nodes[0];
	}

	/**
	 * A user is deleted from the system
	 * So clean up the relevant shares.
	 *
	 * @param string $uid
	 * @param int $shareType
	 */
	public function userDeleted($uid, $shareType) {
		$qb = $this->dbConnection->getQueryBuilder();

		$qb->delete('share')
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter(Share::SHARE_TYPE_REMOTE)))
			->andWhere($qb->expr()->eq('uid_owner', $qb->createNamedParameter($uid)))
			->execute();
	}

	/**
	 * This provider does not support group shares
	 *
	 * @param string $gid
	 */
	public function groupDeleted($gid) {
		return;
	}

	/**
	 * This provider does not support group shares
	 *
	 * @param string $uid
	 * @param string $gid
	 */
	public function userDeletedFromGroup($uid, $gid) {
		return;
	}

}
