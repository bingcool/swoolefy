<?php
namespace Test\Library;

abstract class ListItemFormatter
{
    protected $data;

    protected $listData;

    protected $mapData = [];

    protected $batchFlag = false;

    /**
     * 单个处理
     *
     * @param $data
     * @return void
     */
    public function setData($data)
    {
        $this->data = $data;
        $this->batchFlag = false;
    }

    /**
     * 列表处理
     *
     * @param $listData
     * @return $this
     */
    public function setListData($listData)
    {
        $this->listData = $listData;
        $this->batchFlag = true;
        return $this;
    }

    /**
     * @param array $mapData
     */
    public function setMapData(array $mapData)
    {
        $this->mapData = $mapData;
    }

    public function hasMap()
    {
        return !empty($this->mapData);
    }

    public function buildMapData()
    {

    }

    public function hasMapData($mapKey, $key)
    {
        return array_key_exists($mapKey, $this->mapData) && array_key_exists($key, $this->mapData[$mapKey]);
    }

    public function getMapData($mapKey, $key)
    {
        return $this->mapData[$mapKey][$key] ?? null;
    }

    /**
     * 集合结果
     *
     * @param array $result
     * @param $item
     * @return void
     */
    protected function collectResult(array &$result, $item)
    {
        $result[] = $item;
    }

    /**
     * @return array
     */
    public function result()
    {
        $result = [];

        $this->buildMapData();

        if (!$this->batchFlag) {
            $data = $this->data;
            $result = $this->format($data);
            return $result;
        }

        foreach ($this->listData as $data) {
            $this->collectResult($result, $this->format($data));
        }

        return $result;
    }

    abstract protected function format($data);
}